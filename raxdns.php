<?php

# This will provide us with the basepath of our PHP script here...
define('BASEPATH', realpath(dirname(__FILE__)));


# managed_domains variable and related datafile setup
$managed_domains = array();
if(file_exists(BASEPATH.'/managed_domains')){
	$managed_domains = json_decode(file_get_contents(BASEPATH.'/managed_domains'));
}

# last serial tracking to save resources
$last_serial = array();
if(file_exists(BASEPATH.'/last_serial') && !in_array('--force-all', $argv)){
	$last_serial = (array) json_decode(file_get_contents(BASEPATH.'/last_serial', true));
}

# Get API configuration
if(!file_exists(BASEPATH."/config.conf")){
	die("Configuration file ".BASEPATH."/config.conf does not exist!\n");
}
$api_conf = file_get_contents(BASEPATH."/config.conf");
$api_conf = str_replace("#", ";", $api_conf);
$api_conf = parse_ini_string($api_conf);


###
# http://docs.rackspace.com/cdns/api/v1.0/cdns-devguide/content/GET_searchDomains_v1.0__account__domains_search_domains.html#GET_searchDomains_v1.0__account__domains_search_domains-Request

$supported_record_types = array('MX', 'A', 'AAAA', 'CNAME', 'NS', 'TXT');

# Get and parse the mydns configuration data
$mydns_conf = file_get_contents("/etc/mydns.conf");
$mydns_conf = str_replace("#", ";", $mydns_conf);
$mydns_conf = parse_ini_string($mydns_conf);

$db = mysql_connect($mydns_conf['db-host'], $mydns_conf['db-user'], $mydns_conf['db-password']);
if (!$db) {
    die('Could not connect: ' . mysql_error());
}
mysql_select_db($mydns_conf['database'], $db) or die('Could not select database.');


# Authenticate with the Rackspace API services
$username = $api_conf['api-username'];
$apiKey = $api_conf['api-key'];
$auth_data = exec("curl -s -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{\"auth\":{\"RAX-KSKEY:apiKeyCredentials\":{\"username\": \"$username\",\"apiKey\": \"$apiKey\"}}}' 'https://identity.api.rackspacecloud.com/v2.0/tokens'");
$auth_data = json_decode($auth_data);

$tenantId = $auth_data->access->token->tenant->id;
$token = $auth_data->access->token->id;


# Lazy function for doing cloud stuff.
function cloud_dns($api_call, $datain = false, $methodin = false, $debug_opt = false){
	extract($GLOBALS, EXTR_REFS | EXTR_SKIP);
	$exec_cmd = "curl -s -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-Auth-Token: $token' 'https://dns.api.rackspacecloud.com/v1.0/$tenantId$api_call' ";
	
	
	if($datain){
		$datain = json_encode($datain);
		$exec_cmd .= "-X POST -d '$datain' ";
	}
	
	if($methodin){
		$datain = json_encode($datain);
		$exec_cmd .= "-X $methodin ";
	}


	# First attempt to do action	
	$return_data = exec($exec_cmd);
	$return_data = json_decode($return_data);
	

	# If it's over limit, wait and try again
	while(isset($return_data->overLimit)){
		sleep(5);
		$return_data = exec($exec_cmd);
		$return_data = json_decode($return_data);
	}
	
	# debug output
	if($debug_opt){
		echo "\n$exec_cmd\n";
		echo "\n".var_dump($return_data)."\n";
	}
	
	return $return_data;
}


# We will now go through all the domains in MyDNS that have had updated serials

$result = mysql_query('select * from soa where active = "Y"');

while ($row = mysql_fetch_assoc($result)) {
	# Skip zones that have not had updated serials yet
	if($last_serial[$row['origin']] == $row['serial']){
		continue;
	}

	# ensure this serial
	$last_serial[$row['origin']] = $row['serial'];

	$domainid = false;
	
	# Looking up $row['origin'] in Rackspace Cloud DNS
	$entries = cloud_dns('/domains/search?name='.rtrim($row['origin'], '.'));
	
	# compare our domain name to the records found via search
	if($entries->totalEntries > 0){
		foreach($entries->domains as $v){
			if($v->name == rtrim($row['origin'], '.')){
				$domainid = $v->id;
			}
		}
	}
	
	
	# take action based on if it is found or not
	if($domainid){
		# we found $row[origin], now we'll make sure records are up to date
		$records = cloud_dns('/domains/'.$domainid.'/records');
		
		# ensure current records are up to date
		$local_records = mysql_query("select * from rr where zone = ".$row['id']);
		while ($record_row = mysql_fetch_assoc($local_records)) {
			# check if the record exists and matches mydns
			$record_search = cloud_dns('/domains/'.$domainid.'/records​?type='.$record_row['type'].'&​name='.rtrim($record_row['name'], '.').'&​data='.rtrim($record_row['data'], '.'));
			# add it if does not exist
			if($record_search == NULL){
				$data = array("records" => array(
					array(
					"name" => rtrim($record_row['name'], '.'),
					"type" => $record_row['type'],
					"data" => rtrim($record_row['data'], '.'),
					"ttl" => $record_row['ttl']
					)
				));
				cloud_dns('/domains/'.$domainid.'/records?id='.$record->id, $data);
			}
		}
		
		# clean up old dead records
		foreach($records->records as $record){
			# making sure we don't touch the NS records for stabletransit.com
			if($record->type != "NS" && $record->data != 'dns1.stabletransit.com' && $record->data != 'dns2.stabletransit.com'){
				# check if record exists in mydns
				$search_local_records = mysql_query("select * from rr where zone = ".$row['id']." and active = 'Y' and name = '$record->name.' and (data = '$record->data' or data = '$record->data.') and type = '$record->type'");
				$search_count = mysql_num_rows($search_local_records);
				# delete record if it does not exist
				if($search_count == 0){
					cloud_dns('/domains/'.$domainid.'/records?id='.$record->id, false, 'DELETE');
				}
			}
		}
		
	# if we didn't get a $domainid back, we will create the record.
	}else{
		# $row['origin'] is not setup at rackspace dns. Setting it up now.
		
		# get the local records so we can add them
		$lrecords = array();
		
		$local_records = mysql_query("select * from rr where zone = ".$row['id']);
		while ($record_row = mysql_fetch_assoc($local_records)) {
			$build_record = array(
				"name" => rtrim($record_row['name'], '.'),
				"type" => $record_row['type'],
				"data" => rtrim($record_row['data'], '.'),
				"ttl" => $record_row['ttl'],
				);
			if($record_row['type'] == 'MX')
				$build_record['priority'] = $record_row['aux'];
			
			if(in_array($record_row['type'], $supported_record_types))
				$lrecords []= $build_record;
		}
		
		
		# Setup a data to be json-ified.
		$data = array();
		$data["domains"] = array(
			array(
				"name" => rtrim($row['origin'], '.'),
				"ttl" => $row['ttl'],
				"emailAddress" => 'admin@'.rtrim($row['origin'], '.'),
				"comment" => "rscpdns.php",
				"recordsList" => array("records" => $lrecords),
				"subdomains" => array("domains" => array()),
			)
		);
		
		# add it now!
		cloud_dns('/domains', $data);
	}
	
	# add this domain to the managed domains if it's not already there
	if(!in_array($row['origin'], $managed_domains)){
		$managed_domains []= $row['origin'];
	}
	
}


# remove domains no longer in use
foreach($managed_domains as $domain){
	$result = mysql_query('select * from soa where origin = "'.$domain.'" and active = "Y"');
	$search_count = mysql_num_rows($result);
	
	if($search_count == 0){
		$entries = cloud_dns('/domains/search?name='.rtrim($domain, '.'));
		# hunt it downt an kill it at the rackspace dns api
		if($entries->totalEntries > 0){
			foreach($entries->domains as $v){
				if($v->name == rtrim($domain, '.')){
					cloud_dns('/domains?id='.$v->id, false, 'DELETE');
				}
			}
		}

		# remove the domain from the managed_domains file
		$pos = array_search($domain, $managed_domains);
		unset($managed_domains[$pos]);
		unset($last_serial[$domain]);
	}
}

# save the managed domains
file_put_contents(BASEPATH.'/managed_domains', json_encode($managed_domains));
# save the serials
file_put_contents(BASEPATH.'/last_serial', json_encode($last_serial));


mysql_close($db);
