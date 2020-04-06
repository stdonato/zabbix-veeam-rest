#!/usr/bin/php
<?php

// zabbx-veeam-rest
// ver 0.3
// glukinho

const LOCAL_TIMEZONE = "America/Manaus";

$results = [
	'Success'	=> 0,
	'Warning'	=> 1,
	'Failed'	=> 2
];

$debug_file = '/tmp/zabbix-veeam.log';

$debug = false;


function _dbg($text) {
	global $debug;
	global $debug_file;
	
	
	if ($debug) {
		file_put_contents($debug_file, date("Y-m-d H:i:s - ") . $text . PHP_EOL, FILE_APPEND);
	}
}

// file_put_contents($debug_file, implode(" ", $argv) . PHP_EOL, FILE_APPEND);

$ch = false;
$RestSvcSessionId = false;

function handle_header($ch, $header_line) {
	global $RestSvcSessionId;
	
	$header_arr = explode(": ", $header_line);
	
	if ($header_arr[0] == 'X-RestSvcSessionId') $RestSvcSessionId = $header_arr[1];
	
	return strlen($header_line);
}


function curl($url, $RestSvcSessionId = false, $post = false) {
	global $ch;
	
	$ch = curl_init();
	$curlConfig = array(
		CURLOPT_URL				=> $url,
		CURLOPT_VERBOSE			=> false,
		CURLOPT_HEADER			=> false,
		CURLOPT_POST			=> $post,
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_SSL_VERIFYPEER  => false,
		CURLOPT_SSL_VERIFYHOST  => false,
	);
	curl_setopt_array($ch, $curlConfig);
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'Content-Type: application/xml; charset=utf-8', 
		'Content-Length: 0', 
		'Accept: application/json',
		'X-RestSvcSessionId: '.$RestSvcSessionId
	]);
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, "handle_header");

	$output = curl_exec($ch);
	
	curl_close($ch);
	
	$output = substr($output, 3); // against UTF BOM issue: https://stackoverflow.com/questions/689185/json-decode-returns-null-after-webservice-call
	
	//return json_decode($output);
	return $output;
}


$url_str = $argv[1];
$url = parse_url($url_str);

foreach ($url as $key => $value) $$key = $value;

$action = $argv[2];

//user and password for API authentication
$cred = "user:password";
$cred_encoded = base64_encode($cred);

//
// Veeam REST API authorization
// https://helpcenter.veeam.com/docs/backup/rest/http_authentication.html?ver=95u4
//
// getting token for X-RestSvcSessionId
//
$ch = curl_init();
$curlConfig = array(
	CURLOPT_URL		=> $url_str . "sessionMngr/?v=latest",
	CURLOPT_VERBOSE		=> false,
	CURLOPT_HEADER		=> false,
	CURLOPT_POST		=> true,
	CURLOPT_RETURNTRANSFER	=> true,
	CURLOPT_SSL_VERIFYPEER  => false,
	CURLOPT_SSL_VERIFYHOST  => false,
);
curl_setopt_array($ch, $curlConfig);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
	'Content-Type: application/xml; charset=utf-8', 
	'Content-Length: 0', 
	'Accept: application/json',
	'Authorization: Basic '.$cred_encoded
]);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, "handle_header");

$output = curl_exec($ch);
curl_close($ch);

//echo $RestSvcSessionId;

if (!$RestSvcSessionId) {
	$msg = 'X-RestSvcSessionId header not set';
	_dbg($msg);
	die($msg);
}
//
// end of authorization
//

switch ($action) {
	case "discoverRepo":
		$data = curl($url_str . "repositories?format=Entity", $RestSvcSessionId);
		
		$data = json_decode('{"R'.$data);
		//print_r($data);

		//$data = json_decode($data);
		//echo $discovery->Name;		
		$discovery = new StdClass();
		$discovery->data = array();
	
		foreach ($data->Repositories as $r) {
			$repo = (object) [ '{#REPONAME}' => $r->Name ];
			$discovery->data[] = $repo;
		}
		
		$return = json_encode($discovery);
		
		_dbg($action . " - " . $return);
		
		die($return);
		
		break;
		
		
		
	case "getRepoInfo":
		if (!isset($argv[3])) die('no repo name provided');
	
		$repo_name = $argv[3];
		$data = curl($url_str . "repositories?format=Entity", $RestSvcSessionId);
		$data = json_decode('{"R'.$data);
		// print_r($data);
		
		$repos = array();
		
		foreach ($data->Repositories as $r) {
			if ($repo_name == $r->Name) {
				$repo = (object) [
					'Name' 		=> $r->Name,
					'Capacity' 	=> $r->Capacity,
					'FreeSpace'	=> $r->FreeSpace
				];
			}
		}

		$return = json_encode($repo);
		
		_dbg($action . " - " . $return);
		
		die($return);
		
		break;
		
		
	case "discoverBackupJobs":
		$url_var = "query?type=Job&filter=JobType==Backup,JobType==BackupCopy&format=entities";
		$data = curl($url_str . $url_var, $RestSvcSessionId);
		
		$data = json_decode('{"R'.$data);
		//print_r($data);
		
		$discovery = new StdClass();
		$discovery->data = array();
		
		foreach ($data->Entities->Jobs->Jobs as $b) {
			$backup = (object) [ '{#BACKUPJOBNAME}' => $b->Name , '{#BACKUPJOBTYPE}' => $b->JobType ];
			$discovery->data[] = $backup;
		}
		
		$return = json_encode($discovery);
		
		_dbg($action . " - " . $return);
		
		die($return);
		
		break;

		
	// gets last job info for given JobName
	case "getLastBackupJobInfo":
		if (!isset($argv[3])) die('no job name provided');
		
		$job_name = $argv[3];
		$url_var = "query?type=BackupJobSession&filter=JobName==\"{$job_name}\"&sortDesc=EndTime&pageSize=1&format=entities";
		// print_r($url_var);
		$data = curl($url_str . $url_var, $RestSvcSessionId);
		$data = json_decode('{"R'.$data);
		// print_r($data);
		
		$creationtime = new DateTime($data->Entities->BackupJobSessions->BackupJobSessions[0]->CreationTimeUTC, new DateTimeZone('UTC'));
		$creationtime->setTimezone(new DateTimeZone(LOCAL_TIMEZONE));

		$endtime = new DateTime($data->Entities->BackupJobSessions->BackupJobSessions[0]->EndTimeUTC, new DateTimeZone('UTC'));
		$endtime->setTimezone(new DateTimeZone(LOCAL_TIMEZONE));
		
		$duration = $endtime->getTimestamp() - $creationtime->getTimestamp();
		
		// print_r($creationtime);
		
		$result = new StdClass;
		$result->JobName = $data->Entities->BackupJobSessions->BackupJobSessions[0]->JobName;
		$result->Result = $data->Entities->BackupJobSessions->BackupJobSessions[0]->Result;
		$result->ResultNum = $results[$result->Result];
		$result->CreationTime = $creationtime->format('Y-m-d H:i:s');
		$result->CreationTimeEpoch = $creationtime->getTimestamp();
		$result->EndTime = $endtime->format('Y-m-d H:i:s');
		$result->EndTimeEpoch = $endtime->getTimestamp();
		$result->Duration = $result->EndTimeEpoch - $result->CreationTimeEpoch;
		$result->Comment = "gets last job info for given JobName";

		$return = json_encode($result);
		
		_dbg($action . " - " . $return);
		
		die($return);
		
		break;


		
	case "discoverReplicaJobs":
		$url_var = "query?type=Job&filter=JobType==Replica&format=entities";
		$data = curl($url_str . $url_var, $RestSvcSessionId);
		$data = json_decode('{"R'.$data);
		// print_r($url_var);
		
		// print_r($data);
		
		$discovery = new StdClass();
		$discovery->data = array();
		
		foreach ($data->Entities->Jobs->Jobs as $j) {
			libxml_use_internal_errors(TRUE);
			
			$html = new DOMDocument;
			$html->loadHTML($j->Description);
			$vars = $html->getElementsByTagName('zabbix_replica_time');

			$zabbix_replica_time = $vars->length > 0 ? $vars->item(0)->nodeValue : '{$VEEAM_REPLICA_FAILED_TIME}';
			
			$replica_job = (object) [ '{#REPLICAJOBNAME}' => $j->Name, '{#REPLICAJOBTIME}' => $zabbix_replica_time, '{#REPLICAJOBSCHEDULE}' => $j->ScheduleConfigured ? 'true' : 'false' ];
			$discovery->data[] = $replica_job;
		}
		
		$return = json_encode($discovery);
		
		_dbg($action . " - " . $return);
		
		die($return);
		
		break;


	// gets last SUCCESSFUL job info for given JobName
	case "getLastReplicaJobInfo":
		if (!isset($argv[3])) die('no job name provided');
		
		$job_name = $argv[3];
		$url_var = "query?type=ReplicaJobSession&filter=JobName==\"{$job_name}\";Result==Success&sortDesc=EndTime&pageSize=1&format=entities";
		// print_r($url_var);
		$data = curl($url_str . $url_var, $RestSvcSessionId);
		$data = json_decode('{"R'.$data);
		// print_r($data);
		
		$creationtime = new DateTime($data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->CreationTimeUTC, new DateTimeZone('UTC'));
		$creationtime->setTimezone(new DateTimeZone(LOCAL_TIMEZONE));

		$endtime = new DateTime($data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->EndTimeUTC, new DateTimeZone('UTC'));
		$endtime->setTimezone(new DateTimeZone(LOCAL_TIMEZONE));
		
		$duration = $endtime->getTimestamp() - $creationtime->getTimestamp();
		
		// print_r($creationtime);
		
	
		$result = new StdClass;
		$result->JobName = $data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->JobName;
		$result->Result = $data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->Result;
		$result->ResultNum = $results[$result->Result];
		$result->CreationTime = $creationtime->format('Y-m-d H:i:s');
		$result->CreationTimeEpoch = $creationtime->getTimestamp();
		$result->EndTime = $endtime->format('Y-m-d H:i:s');
		$result->EndTimeEpoch = $endtime->getTimestamp();
		$result->Duration = $result->EndTimeEpoch - $result->CreationTimeEpoch;
		$result->Comment = "gets last job info for given JobName";

		$return = json_encode($result);
		
		_dbg($action . " - " . $return);
		
		die($return);
		
		break;


	case "discoverAgentJobs":
		$url_var = "query?type=AgentBackupJob&format=entities";
		$data = curl($url_str . $url_var, $RestSvcSessionId);
		$data = json_decode('{"R'.$data);
		//print_r($data);
		
		$discovery = new StdClass();
		$discovery->data = array();
				
		foreach ($data->Entities->AgentBackupJob->AgentBackupJobs as $j) {
			$agent_job = (object) [ '{#AGENTJOBNAME}' => $j->Name ];
			$discovery->data[] = $agent_job;
		}
		
		$return = json_encode($discovery);
		
		_dbg($action . " - " . $return);
		
		die($return);
		
		break;
		
		
	default:
		_dbg($action . " - unknown action");
		die('unknown action: '.$action);
}
