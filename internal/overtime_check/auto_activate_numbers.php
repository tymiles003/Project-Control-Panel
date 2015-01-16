#!/usr/bin/php -q
<?php
date_default_timezone_set('America/Vancouver');
$vancouver_date = date('Y-m-d').' 00:00:00';

date_default_timezone_set('UTC');
$current_utc_time = time();


$stratcom_report_staff = array(
	'tm.tech@stratcom.ca',
	'sviatlana.vernikouskaya@stratcom.ca',
	'Voice.Broadcasting@ndp.ca',
	'ndpbvm@stratcom.ca',
);



define ("DB_HOST", "localhost");
define ("DB_USER", "ivr_gui");
define ("DB_PASSWORD", "snow1in1the1summer");
define ("DB_IVR", "IVR_data"); //IVR_data_test
define ("DB_CDR", "asteriskcdrdb");

$con = dbc_ivr();
$query_active_projects = "SELECT id, name, time_start, time_end, redial_interval, redial_rounds, fndp_finished_round FROM dean_poll_projects WHERE active=1 AND fndp_active=1 AND project_state='approved' AND project_type='fndp' AND project_date<='".$vancouver_date."'";
$result_active_projects = mysql_query($query_active_projects) OR die("Error selecting active projects");
while ($row_active_projects = mysql_fetch_array($result_active_projects)) {

	$result_project_complete = mysql_query("SELECT COUNT(*) FROM dialout_numbers WHERE projectid=".$row_active_projects['id']." AND (result is null or result='') AND attempts<".$row_active_projects['redial_rounds']) OR die("Error selecting active numbers");
	$row_project_complete = mysql_fetch_array($result_project_complete);
	if ($row_project_complete[0] == 0) {
		mysql_query("UPDATE dean_poll_projects SET active=0, fndp_active=0, project_state='complete' WHERE id=".$row_active_projects['id']) OR die("Error completing project");

		$id = $row_active_projects['id'];
		$name = $row_active_projects['name'];
		mkdir('/var/www/html/fndp/archive/'.$id.'/');
		$fname = getPDFReport($id);
		$output_name = getDetailOutput($id);
		

		require '/var/www/html/simpleivr/phpmailer/PHPMailerAutoload.php';
		$mail = new PHPMailer;
		$mail->From = 'tm.tech@stratcom.ca';
		$mail->FromName = 'TM Tech';
		foreach ($stratcom_report_staff as $item) {
			$mail->addAddress($item);
		}
		$mail->WordWrap = 50;
		$mail->addAttachment($fname);
		//$mail->addAttachment($output_name);
		$mail->isHTML(true);
		$mail->Subject = "Project ".$id." ".$name." Report";
		$mail->Body = "Please see attachment";
		if(!$mail->send()) {
		   echo 'Message could not be sent.';
		   echo 'Mailer Error: ' . $mail->ErrorInfo;
		   exit;
		}

		echo "set complete for project ".$row_active_projects['id'];

		continue;
	}
	
	date_default_timezone_set('America/Vancouver');
	$vancouver_datetime_minus_interval = date('Y-m-d H:i:s', time()-($row_active_projects['redial_interval']*60));
	date_default_timezone_set('UTC');
	
	if ($row_active_projects['redial_interval'] == 'next_day_standard' OR $row_active_projects['redial_interval'] == 'next_day_custom') {
		$supposed_today_round = $row_active_projects['fndp_finished_round']+1;
		$query_valid_records = "SELECT id, phonenumber, timezone FROM dialout_numbers WHERE active=0 AND projectid=".$row_active_projects['id']." AND (result is null OR result='') AND attempts<".$supposed_today_round;
		$result_valid_records = mysql_query($query_valid_records) OR die("Error selecting valid records");
		if (mysql_num_rows($result_valid_records) == 0) { // project should be deactivated for the day, finished rounds should be set to today's supposed round
			mysql_query("UPDATE dean_poll_projects SET active=0, fndp_active=0, fndp_finished_round=".$supposed_today_round." WHERE id=".$row_active_projects['id']) OR die("Error deactivating project");
		} else {
			while ($row_valid_records = mysql_fetch_array($result_valid_records)) {
				if (($row_active_projects['time_start'] < date('H:i', ($current_utc_time+($row_valid_records['timezone']*3600)))) AND ($row_active_projects['time_end'] > date('H:i', ($current_utc_time+($row_valid_records['timezone']*3600))))) {
					mysql_query("UPDATE dialout_numbers SET active=1 WHERE id=".$row_valid_records['id']) OR die("Error activating number");
				}
			}
		}
	} else {
		$query_valid_records = "SELECT id, phonenumber, timezone FROM dialout_numbers WHERE active=0 AND lastattempt<'".$vancouver_datetime_minus_interval."' AND projectid=".$row_active_projects['id']." AND (result is null OR result='') AND attempts<".$row_active_projects['redial_rounds'];
		$result_valid_records = mysql_query($query_valid_records) OR die("Error selecting valid records");
		while ($row_valid_records = mysql_fetch_array($result_valid_records)) {
			if (($row_active_projects['time_start'] < date('H:i', ($current_utc_time+($row_valid_records['timezone']*3600)))) AND ($row_active_projects['time_end'] > date('H:i', ($current_utc_time+($row_valid_records['timezone']*3600))))) {
				mysql_query("UPDATE dialout_numbers SET active=1 WHERE id=".$row_valid_records['id']) OR die("Error activating number");
				
			}
		}
	}
}


function dbc_ivr() {
	$con = mysql_connect(DB_HOST,DB_USER,DB_PASSWORD);
	if (!$con)
		die('Could not connect db: ' . mysql_error());
	if( !mysql_select_db(DB_IVR, $con) )
		die('Could not select db: ' . mysql_error());
	return $con;
}

function dbc_cdr() {
	$con_cdr = mysql_connect(DB_HOST,DB_USER,DB_PASSWORD);
	if (!$con_cdr)
		die('Could not connect db: ' . mysql_error());
	if( !mysql_select_db(DB_CDR, $con_cdr) )
		die('Could not select db: ' . mysql_error());
	return $con_cdr;
}

function getPDFReport($id) {

	include_once ("/var/www/html/simpleivr/mpdf/mpdf.php");

	$glb_dialplan_context = array(
		'app-evan-bvm' => array('description' => 'BVM [regular]', 'type' => 'BVM', 'options' => 0),
		'app-ibvm-press-1-to-repeat' => array('description' => 'IBVM [press 1 to repeat msg]', 'type' => 'IBVM', 'options' => 1),
		'app-ibvm-press-2-to-leave-message' => array('description' => 'IBVM [press 2 to leave msg]', 'type' => 'IBVM', 'options' => 1),
		'app-ibvm-press-3-to-dnc' => array('description' => 'IBVM [press 3 to add to DNC]', 'type' => 'IBVM', 'options' => 1),
		'app-ibvm-1-repeat-2-message' => array('description' => 'IBVM [1 to repeat, 2 to message]', 'type' => 'IBVM', 'options' => 2),
		'app-ibvm-1-repeat-2-message-3-dnc' => array('description' => 'IBVM [1 repeat, 2 message, 3 dnc]', 'type' => 'IBVM', 'options' => 3),
		'app-ibvm-1-repeat-3-dnc' => array('description' => 'IBVM [1 to repeat, 3 to dnc]', 'type' => 'IBVM', 'options' => 2),
		'app-ibvm-2-message-3-dnc' => array('description' => 'IBVM [2 to message, 3 to dnc]', 'type' => 'IBVM', 'options' => 2),

		'app-interactive-bvm-generic-thankyou' => array('description' => 'IBVM [1 question generic thankyou]', 'type' => 'IBVM', 'options' => 1),
	);

	$wav_file_path = "/var/spool/asterisk/PollingIVR/";
	$live_wav_suffix = "00.wav";
	$machine_wav_suffix = "99.wav";

	$con = dbc_ivr();

	$result = mysql_query("SELECT * FROM dean_poll_projects WHERE id=".$id);
	$row = mysql_fetch_array($result);
	$projectname = $row['name'];
	$pif = $row['pif_number'];
	$dialplanraw = $row['dialplan_context'];
	if (isset($glb_dialplan_context[$dialplanraw])) {
		$dialplanname = $glb_dialplan_context[$dialplanraw]['description'];
	} else {
		$dialplanname = "Custom IVR";
	}

	if ($dialplanname == "Custom IVR") {
		$livemessageduration = "Custom IVR";
		$ammessageduration = "Custom IVR";
		$livemessagedurationwithivr = "Custom IVR";
	} else {
		$livemessagepath = $wav_file_path.$id."/".$id.$live_wav_suffix;
		$ammessagepath = $wav_file_path.$id."/".$id.$machine_wav_suffix;
		if (isset($livemessagepath)) {
			$livemessageduration = getDuration($livemessagepath);
		} else {
			$livemessageduration = "N/A";
		}
		if (isset($ammessagepath)) {
			$ammessageduration = getDuration($ammessagepath);
		} else {
			$ammessageduration = "N/A";
		}
		if ($livemessageduration == "N/A") {
			$livemessagedurationwithivr = "N/A";
		} else {
			$livemessagedurationwithivr = $livemessageduration + $glb_dialplan_context[$dialplanraw]['options']*5;
		}
	}

	if ($livemessagedurationwithivr > 60) {
		$priceperlivemessage = (0.055/60)*$livemessagedurationwithivr;
	} else {
		$priceperlivemessage = 0.055;
	}
	$priceperlivemessage = "$".$priceperlivemessage;

	if ($ammessageduration > 60) {
		$priceperammessage = (0.055/60)*$ammessageduration;
	} else {
		$priceperammessage = 0.055;
	}
	$priceperammessage = "$".$priceperammessage;

	if ($livemessageduration != "Custom IVR" AND $livemessageduration != "N/A") {
		$livemessageduration = $livemessageduration." seconds";
	}
	if ($ammessageduration != "Custom IVR" AND $ammessageduration != "N/A") {
		$ammessageduration = $ammessageduration." seconds";
	}
	if ($livemessagedurationwithivr != "Custom IVR" AND $livemessagedurationwithivr != "N/A") {
		$livemessagedurationwithivr = $livemessagedurationwithivr." seconds";
	}

	$callerstring = $row['callerid'];
	$matches = array();
	preg_match('/"(.*?)"/s', $callerstring, $matches);
	if (isset($matches[1])) {
		$callername = $matches[1];
	} else {
		$callername = "";
	}
	preg_match('/<(.*?)>/s', $callerstring, $matches);
	if (isset($matches[1])) {
		$callerid = $matches[1];
	} else {
		$callerid = $callerstring;
	}

	$q = "SELECT * FROM project_billing_info WHERE projectid=".$id;
	$r = mysql_query($q);
	$row = mysql_fetch_array($r);
	$billname = $row['billname'];
	$billaddress = $row['billaddress'];
	$billphone = $row['billphone'];
	$billemail = $row['billemail'];
	$projecttype = $row['billtype'];


	$result = mysql_query("SELECT COUNT(*) FROM dnc_list WHERE projectid=".$id);
	$row = mysql_fetch_array($result);
	$DNC_added = $row[0];

	$result = mysql_query("SELECT COUNT(*) FROM dialout_numbers WHERE prov!='TEST' AND result!='invalid' AND result!='removed' AND projectid=".$id);
	$row = mysql_fetch_array($result);
	$total_num = $row[0];

	$result = mysql_query("SELECT COUNT(*) FROM dialout_numbers WHERE prov!='TEST' AND (result='HUMAN' OR result like'PRESS%') AND projectid=".$id);
	$row = mysql_fetch_array($result);
	$num_human = $row[0];

	$result = mysql_query("SELECT COUNT(*) FROM dialout_numbers WHERE prov!='TEST' AND (result='MACHINE' OR result='NOTSURE') AND projectid=".$id);
	$row = mysql_fetch_array($result);
	$num_machine = $row[0];

	$result = mysql_query("SELECT COUNT(*) FROM dialout_numbers WHERE prov!='TEST' AND (result is NULL OR result='') AND projectid=".$id);
	$row = mysql_fetch_array($result);
	$num_noreach = $row[0];

	$result=mysql_query("SELECT MAX(lastattempt) FROM dialout_numbers WHERE prov!='TEST' AND projectid=".$id);
	$row = mysql_fetch_array($result);
	$projectend = substr($row[0],0,10);

	$result=mysql_query("SELECT MIN(lastattempt) FROM dialout_numbers WHERE prov!='TEST' AND attempts>0 AND projectid=".$id);
	$row = mysql_fetch_array($result);
	$projectstart = substr($row[0],0,10);

	mysql_close($con);

	$num_delivered = $num_human + $num_machine;
	if ($num_delivered > 0) {
		$connectpercentage = number_format(($num_delivered/$total_num)*100, 0)."%";
		$amindelivered = number_format(($num_machine/$num_delivered)*100, 1)."%";
		$humanindelivered = number_format(($num_human/$num_delivered)*100, 1)."%";
	} else {
		$connectpercentage = "0%";
		$amindelivered = "0%";
		$humanindelivered = "0%";
	}

	$aminall = number_format(($num_machine/$total_num)*100, 1)."%";
	$humaninall = number_format(($num_human/$total_num)*100, 1)."%";
	$undeliveredinall = number_format(($num_noreach/$total_num)*100, 1)."%";

	$reportdate = date('Y-m-d');

	$mpdf=new mPDF('UTF-8','Letter','','',20,15,48,25,10,10); 
	$mpdf->SetTitle("STRATCOM NDP BVM Report");
	$mpdf->SetAuthor("STRATCOM");
	$mpdf->SetDisplayMode('fullpage');

	$html = '
	<html>
	<head>
	<style>
	body {font-family: sans-serif;
		font-size: 10pt;
	}
	p {    margin: 0pt;
	}
	td { vertical-align: top; }
	.items td {
		border-left: 0.1mm solid #000000;
		border-right: 0.1mm solid #000000;
	}
	table thead td { background-color: #EEEEEE;
		text-align: center;
		border: 0.1mm solid #000000;
	}
	.items td.blanktotal {
		background-color: #FFFFFF;
		border: 0mm none #000000;
		border-top: 0.1mm solid #000000;
		border-right: 0.1mm solid #000000;
	}
	.items td.totals {
		text-align: right;
		border: 0.1mm solid #000000;
	}
	</style>
	</head>
	<body>

	<!--mpdf
	<htmlpageheader name="myheader">
	<table width="100%"><tr>
	<td><span style="color: #525051; font: normal 35pt Helvetica;">STRAT</span>
	<span style="color: #ffbd20; font: normal 35pt Helvetica; margin-left: -8px;">COM</span>
	<div style="color: #525051; letter-spacing: 0.06em; font: bold 10pt Helvetica, Arial; margin-top: -8px;">STRATEGIC COMMUNICATIONS INC.</div></td>
	</tr></table>
	</htmlpageheader>

	<htmlpagefooter name="myfooter">
	<div style="border-top: 1px solid #000000; font-size: 9pt; text-align: center; padding-top: 3mm; ">
	<div>Toronto: 1179 King Street West Suite 202 Toronto ON M6K 3C5 PH 416.537.6100 FX 416.588.3490</div>
	<div>Vancouver: 1770 West 7th Ave. Suite 305 Vancouver BC V6J 4Y6 PH 604.681.3030 FX 604.681.2025</div>
	<div>Ottawa: 100 Sparks Street 8th Floor Ottawa ON K1P 5B7 PH 613.916.6215 FX 613.238.9997</div>
	</div>
	</htmlpagefooter>

	<sethtmlpageheader name="myheader" value="on" show-this-page="1" />
	<sethtmlpagefooter name="myfooter" value="on" />
	mpdf-->


	<table width="100%" style="font-size: 11pt; cellpadding="8">
	<tr>
	<td align="left" width="25%">PIF #:</td>
	<td align="left" width="25%">'.$pif.'</td>
	<td align="left" width="25%">Report Date:</td>
	<td align="left" width="25%">'.$reportdate.'</td>
	</tr>
	<tr>
	<td>&nbsp;</td>
	</tr>
	<tr>
	<td>Project Name:</td>
	<td colspan=3>'.$projectname.'</td>
	</tr>
	<tr>
	<td>Dial Plan:</td>
	<td colspan=3>'.$dialplanname.'</td>
	</tr>
	<tr>
	<td>Project Type:</td>
	<td>'.$projecttype.'</td>
	<td>List Size:</td>
	<td>'.$total_num.'</td>
	</tr>
	<tr>
	<td>Caller ID Name:</td>
	<td>'.$callername.'</td>
	<td>Project Start Date:</td>
	<td>'.$projectstart.'</td>
	</tr>
	<tr>
	<td>Caller ID Phone #:</td>
	<td>'.$callerid.'</td>
	<td>Project End Date:</td>
	<td>'.$projectend.'</td>
	</tr>

	<tr>
	<td>&nbsp;</td>
	</tr>


	<tr>
	<td>Billing Name:</td>
	<td colspan=3>'.$billname.'</td>
	</tr>
	<tr>
	<td>Billing Address:</td>
	<td colspan=3>'.$billaddress.'</td>
	</tr>
	<tr>
	<td>Billing Phone:</td>
	<td colspan=3>'.$billphone.'</td>
	</tr>
	<tr>
	<td>Billing Email:</td>
	<td colspan=3>'.$billemail.'</td>
	</tr>
	<tr>
	<td>&nbsp;</td>
	</tr>
	<tr>
	<td colspan=2>Live Message Duration:</td>
	<td colspan=2>'.$livemessageduration.'</td>
	</tr>
	<tr>
	<td colspan=2>Live Message Duration (including Dial Plan IVR):</td>
	<td colspan=2>'.$livemessagedurationwithivr.'</td>
	</tr>
	<tr>
	<td colspan=2>Answer Machine Message Duration:</td>
	<td colspan=2>'.$ammessageduration.'</td>
	</tr>
	<tr>
	<td colspan=2>Cost per Live Message Delivered:</td>
	<td colspan=2>'.$priceperlivemessage.'</td>
	</tr>
	<tr>
	<td colspan=2>Cost per Answer Machine Message Delivered:</td>
	<td colspan=2>'.$priceperammessage.'</td>
	</tr>

	<tr>
	<td>&nbsp;</td>
	</tr>

	<tr>
	<td style="font: bold 10pt Helvetica, Arial;">Contact Summary</td>
	</tr>
	<tr>
	<td>&nbsp;</td>
	</tr>
	<tr>
	<td>Contact Rate:</td>
	<td colspan=3>'.$connectpercentage.'</td>
	</tr>
	<tr>
	<td>&nbsp;</td>
	</tr>
	<tr>
	<td>&nbsp;</td>
	<td>Count</td>
	<td>Group %</td>
	<td>Overall %</td>
	</tr>
	<tr>
	<td>Live Message</td>
	<td>'.$num_human.'</td>
	<td>'.$humanindelivered.'</td>
	<td>'.$humaninall.'</td>
	</tr>
	<tr>
	<td>AM Message</td>
	<td>'.$num_machine.'</td>
	<td>'.$amindelivered.'</td>
	<td>'.$aminall.'</td>
	</tr>
	<tr>
	<td>Undeliverable</td>
	<td>'.$num_noreach.'</td>
	<td>&nbsp;</td>
	<td>'.$undeliveredinall.'</td>
	</tr>
	<tr>
	<td>TOTAL</td>
	<td>'.$total_num.'</td>
	<td>&nbsp;</td>
	<td>100%</td>
	</tr>


	</tbody>
	</table>
	</body>
	</html>
	';

	$mpdf->WriteHTML($html);
	$fname = "/var/www/html/fndp/archive/".$id."/toClient_".$projectname."_".date("M_d").".pdf";
	$mpdf->Output($fname,'F');

	return $fname;
}

function getDuration($file) {
	$fp = fopen($file, 'r');
	$size_in_bytes = filesize($file);
	fseek($fp, 20);
	$rawheader = fread($fp, 16);
	$header = unpack('vtype/vchannels/Vsamplerate/Vbytespersec/valignment/vbits',$rawheader);
	$sec = ceil($size_in_bytes/$header['bytespersec']);
	return $sec;
}

function getDetailOutput($id) {
	$con = dbc_ivr();
	$q = "SELECT * FROM dean_poll_projects WHERE id=".$id;
	$r = mysql_query ($q);
	$row = mysql_fetch_assoc($r);
	
	$project_name = $row["name"];
	$extra_field_titles = $row["extra_field_titles"];

	$csv_output  = "Last Attempt Time".", ";
	$csv_output .= "Phone Number".", ";
	$csv_output .= "Result".", ";
	$csv_output .= "Attemps".", ";
	$csv_output .= "Billtime in seconds".", ";
	$csv_output .= $extra_field_titles.", ";

	$csv_output .= "\n";

	$con = dbc_cdr();

	$values = mysql_query("SELECT COUNT(*) FROM cdr WHERE accountcode LIKE '".$id."<\%>%'") OR die("error executing cdr SQL");
	$rowr = mysql_fetch_row($values);

	$cdr_records = array();

	if ($rowr[0] > 0) {
		
		$con = dbc_cdr();
		$values = mysql_query("SELECT billsec, accountcode FROM cdr WHERE accountcode LIKE '".$id."<\%>%'") OR die("error executing if SQL");
		while ($rowr = mysql_fetch_array($values)) {
			$pieces = explode("<%>", $rowr['accountcode']);
			$rowr['projectid'] = $pieces[0];
			$rowr['phonenumber'] = $pieces[1];
			$cdr_records[] = $rowr;
		}
		$con = dbc_ivr();

		foreach ($cdr_records as $cdr_record) {
			$single_billsec = ceil($cdr_record['billsec'] / 30) * 30;
			$values = mysql_query("SELECT result, attempts, lastattempt, extra_fields FROM dialout_numbers WHERE prov!='TEST' AND projectid=".$cdr_record['projectid']." AND phonenumber='".$cdr_record['phonenumber']."'") OR die("error executing if inside SQL");
			if (mysql_num_rows($values) > 0) {
				$rowr = mysql_fetch_array($values);
				$csv_output .= $rowr['lastattempt'].", ";
				$csv_output .= $cdr_record['phonenumber'].", ";
				$csv_output .= $rowr['result'].", ";
				$csv_output .= $rowr['attempts'].", ";
				$csv_output .= $cdr_record['billsec'].", ";
				$csv_output .= $rowr['extra_fields'].", ";
				
				$csv_output .= "\n";
			}
		}
		$values = mysql_query("SELECT projectid, phonenumber, result, attempts, lastattempt, extra_fields FROM dialout_numbers WHERE prov!='TEST' AND projectid=".$id." AND result IS NULL") OR die("error executing else SQL");
		while ($rowr = mysql_fetch_array($values)) {
			$csv_output .= $rowr['lastattempt'].", ";
			$csv_output .= $rowr['phonenumber'].", ";
			$csv_output .= "NIS OR NVM".", ";
			$csv_output .= $rowr['attempts'].", ";
			$csv_output .= $rowr['extra_fields'].", ";
			
			$csv_output .= "\n";
		}
	} else {
		$con = dbc_ivr();

		$values = mysql_query("SELECT projectid, phonenumber, result, attempts, lastattempt, extra_fields FROM dialout_numbers WHERE prov!='TEST' AND projectid=".$id." AND result IS NOT NULL") OR die("error executing else SQL");
		while ($rowr = mysql_fetch_array($values)) {
			$csv_output .= $rowr['lastattempt'].", ";
			$csv_output .= $rowr['phonenumber'].", ";
			$csv_output .= $rowr['result'].", ";
			$csv_output .= $rowr['attempts'].", ";
			$csv_output .= $rowr['extra_fields'].", ";

			$csv_output .= "\n";
		}

		$values = mysql_query("SELECT projectid, phonenumber, result, attempts, lastattempt, extra_fields FROM dialout_numbers WHERE prov!='TEST' AND projectid=".$id." AND result IS NULL") OR die("error executing else SQL");
		while ($rowr = mysql_fetch_array($values)) {
			$csv_output .= $rowr['lastattempt'].", ";
			$csv_output .= $rowr['phonenumber'].", ";
			$csv_output .= "NIS OR NVM".", ";
			$csv_output .= $rowr['attempts'].", ";
			$csv_output .= $rowr['extra_fields'].", ";

			$csv_output .= "\n";
		}
	}

	mysql_close($con);

	$output_name = '/var/www/html/fndp/archive/'.$id.'/'.$project_name.'_Detail_Report.csv';

	file_put_contents($output_name, $csv_output);
	
	return $output_name;
}

?>