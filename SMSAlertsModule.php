<?php
namespace Vanderbilt\SMSAlertsModule;

class SMSAlertsModule extends \ExternalModules\AbstractExternalModule
{
	function redcap_module_project_enable($version, $projectId){
		$originalPid = $_GET['pid'];
		$_GET['pid'] = $projectId;

		// Set the last processed log so that logs after this point in time will be processed.
		$this->setLastProcessedLogTimestampToCurrentTime();

		// Put the pid back the way it was before this cron job (likely doesn't matter, but wanted to be safe)
		$_GET['pid'] = $originalPid;
	}

	function cron(){
		$originalPid = $_GET['pid'];

		foreach($this->framework->getProjectsWithModuleEnabled() as $projectId){
			$_GET['pid'] = $projectId;

			$recordCreationAlertsEnabled = $this->getProjectSetting('record-creation-alerts-enabled');
			$recipients = array_filter($this->getProjectSetting('recipients'));
			if(!$recordCreationAlertsEnabled || empty($recipients)){
				continue;
			}

			$lastLogTimestamp = $this->getProjectSetting('last-processed-log-timestamp');
			if(!$lastLogTimestamp){
				$currentTimestamp = $this->setLastProcessedLogTimestampToCurrentTime();
				$message = "The last processed log should have been set at project enable time before the cron job ran for project $projectId!  Events prior to $currentTimestamp will be ignored.";
				$this->logError($message);
				continue;
			}

			$aWeekAgo = time()-60*60*24*7;
			if(strtotime($lastLogTimestamp) < $aWeekAgo){
				$currentTimestamp = $this->setLastProcessedLogTimestampToCurrentTime();
				$message = "The cron job has not run for more than a week on project $projectId!  Any event log entries between $lastLogTimestamp and $currentTimestamp will be ignored to prevent a flood of SMSes and/or slow queries.";
				$this->logError($message);
				continue;
			}

			$results = $this->query("
				select ts, pk
				from redcap_log_event
				where
					event = 'INSERT'
					and project_id = $projectId
					and log_event_id > (select log_event_id from redcap_log_event where ts <= $lastLogTimestamp order by ts desc limit 1)
				and description = 'Create Record'
				order by log_event_id asc;
			");

			$sendSMS = $this->getSendFunction($projectId, $recipients);
			while($row = $results->fetch_assoc()){
				$lastLogTimestamp = $row['ts'];
				$sendSMS($row['pk']);
			}

			$this->setLastProcessedLogTimestamp($lastLogTimestamp);
		}

		// Put the pid back the way it was before this cron job (likely doesn't matter, but wanted to be safe)
		$_GET['pid'] = $originalPid;

		return 'The ' . $this->getModuleName() . ' External Module job completed successfully.';
	}
	
	private function setLastProcessedLogTimestampToCurrentTime(){
		$results = $this->query("select ts from redcap_log_event order by log_event_id desc limit 1");
		$row = $results->fetch_assoc();
		$timestamp = $row['ts'];

		$this->setLastProcessedLogTimestamp($timestamp);

		return $timestamp;
	}

	private function getSendFunction($projectId, $recipients){
		$results = $this->query("
			select twilio_account_sid, twilio_auth_token, twilio_from_number
			from redcap_projects where project_id = $projectId;
		");

		$twilioInfo = $results->fetch_assoc();

		$twilioSid = $twilioInfo['twilio_account_sid'];
		$twilioAuthToken = $twilioInfo['twilio_auth_token'];
		$twilioFromNumber = $twilioInfo['twilio_from_number'];

		if(empty($twilioSid) || empty($twilioAuthToken) || empty($twilioFromNumber)){
			$twilioClient = null;
		}
		else{
			$twilioClient = new \Services_Twilio($twilioSid, $twilioAuthToken);
		}

		return function($recordId) use ($projectId, $recipients, $twilioFromNumber, $twilioClient){
			if(!$twilioClient){
				$this->log("Not sending SMSes for record $recordId since a Twilio account is not configured on this project.");
				return;
			}

			$url = rtrim(APP_PATH_WEBROOT_FULL, '/') . APP_PATH_WEBROOT . "index.php?pid=" . $projectId;
			$message = "Record $recordId has been added to the following project: $url";

			foreach($recipients as $recipient){
				$success = \TwilioRC::sendSMS($message, $recipient, $twilioClient, $twilioFromNumber, false, $projectId);
				if($success) {
					$this->log("SMS sent for record $recordId");
				}
				else{
					$message = "An error occurred while sending an SMS alert to $recipient for project $projectId.";
					$this->logError($message);
				}
			}
		};
	}

	private function setLastProcessedLogTimestamp($lastLogTimestamp){
		$this->setProjectSetting('last-processed-log-timestamp', $lastLogTimestamp);
		$dateString = date('r', strtotime($lastLogTimestamp));
		$this->log("Setting last log timestamp to $dateString ($lastLogTimestamp).");
	}

	private function logError($message){
		$this->log($message);
		error_log($this->getModuleName() . " Module: $message");
	}
}