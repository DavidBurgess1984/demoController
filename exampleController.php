<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Class : MQTTcli
 * 
 * Main CI controller for publishing attendance data from TCSA2 db to MQTT broker.
 * Run on a CRON job that executes every 15 seconds (4 cron jobs in linux set to 
 * run in minute intervals after sleeping for 0, 15, 30, 45 seconds).
 * 
 * We use the Mosquitto-PHP library taken from https://github.com/mgdm/Mosquitto-PHP
 * */
class MQTTcli extends CI_Controller {

    private $subscribeTopics;

    public function __construct(){
     
     parent::__construct();

     if(!$this->input->is_cli_request()){
      exit('No url access allowed');
     }
     
     $this->load->model('Event_model');
     $this->load->model('Attendances_model');
     $this->load->model('TktEntitlement_model');
     $this->load->model('Config_model');
     $this->publishTopics = array();
     
    }

    //Main command that publishes attendance information to MQTT broker
	public function index()
	{
		 //$demoTime = $this->getDemoGameTime();
	     $demoTime = $this->getGameTime();
	     
	     $eventViewSummaryGetter = new EventViewSummaryGetterToMQTT($demoTime) ;
    	 $evSummaryGetterCommand = new EventViewSummaryToMQTTCommand($eventViewSummaryGetter);
    	 $evSummaryGetterCommand->execute();
	 
    	 $evEntranceAttdGetter = new EventViewAttendancesByEntranceToMQTT($demoTime);
    	 $evEntranceAttdGetterCommand = new EventViewAttendancesByEntranceToMQTTCommand($evEntranceAttdGetter);
    	 $evEntranceAttdGetterCommand->execute();
    
    	 $evGateAttdGetter = new EventViewAttendancesByGateToMQTT($demoTime);
    	 $evGateAttdGetterCommand = new EventViewAttendancesByGateToMQTTCommand($evGateAttdGetter);
    	 $evGateAttdGetterCommand->execute();
    
    	 $evTrAttdGetter = new EventViewAttendancesByTrToMQTT($demoTime);
    	 $evTrGetterCommand = new EventViewAttendancesBtTrToMQTTCommand($evTrAttdGetter);
    	 $evTrGetterCommand->execute();
	}
	
	private function getGameTime(){
		$this->Config_model->setDefaultTimeStart();
		return $this->Config_model->getDateTimeMinusFiveSeconds();
	}
	
	private function getDemoGameTime(){
    	 $this->Config_model->setDemoDateTime();
    	 return $this->Config_model->getDemoDateTimeMinusFiveSeconds();
	}
	
	//set the demo start time
	public function resetDemo(){
	   $this->Config_model->setDefaultTimeDemoStart();
	   $newTime = $this->Config_model->getDemoDateTime();
	   echo "Demo time reset to {$newTime}\n";
	}
	
	public function doSubscribe(){
	 
	 
	
     
	}
	
	//for debugging on the CLI
	public function getEventConfigData(){
	   echo "demo time: ".$this->Config_model->getDemoDateTime()."\n";
	}
	
	 
}

/**
 * Abstract class for EventViewAttendance Classes
 * Based on Command Design Pattern
 */
abstract class Receiver{
 
 protected $demoDT;  //datetime string
 protected $client;
 
 function __construct($demoDateTime){
    
   if(!empty($demoDateTime)){
     $this->demoDT = $demoDateTime;
   } else {
     $this->demoDT = date('Y-m-d H:i:s');
   }
   
   $this->client = new Mosquitto\Client();
   $this->client->connect("10.254.249.24", 1883);
   
   $this->client->onConnect('connect');
   $this->client->onDisconnect('disconnect');
   $this->client->onSubscribe('subscribe');
   $this->client->onMessage('message');
   $this->client->onPublish('publish');
 
 }
 
 
 
 abstract function doExecute();
}


/**
 * Class: EventViewSummaryGetterToMQTT
 * 
 * Gets the Event View summary data and attendance information for whole venue.
 * Publishes to MQTT broker on +/+/summary
 * Data retrieved from current time minus 5 seconds
 */
class EventViewSummaryGetterToMQTT extends Receiver{
 
 /*
 private $demoDT;  //datetime string
 
 function __construct($demoDateTime){
   
   if(!empty($demoDateTime)){
      $this->demoDT = $demoDateTime;
   } else {
      $this->demoDT = date('Y-m-d H:i:s');
   }
  
 }*/
 
	
 public function doExecute(){

  $CI =& get_instance();

  $eventInfo = $CI->Event_model->getEventSummary();
  $totalAttendances = $CI->Attendances_model->getTotalAttendances('', $this->demoDT);
  $totalErrors = $CI->TktEntitlement_model->getTotalErrorCount('', $this->demoDT);

  $date = new DateTime();
  $date->modify("-5 second");
  $dateTimeArray = array('date' => $date->format('c'));
  
  

 /*for($i=0;$i<1000;$i++){
    array_push($totalAttendances, array($i => 'sadasdasdsadasdsadasd') );
  }*/
  
  $payloadArray = array_merge($eventInfo, $totalAttendances,$totalErrors,$dateTimeArray);
  $payloadJSON = json_encode($payloadArray);
  
  $eventSummaryTopic = $this->getEventSummaryTopic($eventInfo['venue_id'], $eventInfo['event_id']);
  //$entAttendances = $CI->Attendances_model->getVenueEntranceAttendances();

  
  sleep(1);
  //$client->onLog('mqttLog');
  $this->client->publish($eventSummaryTopic, $payloadJSON, 1);
  echo "Event Summary: \n";

 }
  
 private function getEventSummaryTopic($venueID, $eventID){
    $vStr = "v_".$venueID;
    $eStr = "e_".$eventID;
    return implode('/',array($vStr ,$eStr,'summary'));
 }
}



/**
 * Class: EventViewAttendancesByEntranceToMQTT
 *
 * Gets attendance information per entrance and publishes to MQTT broker on +/+/entrances
 * Data retrieved from current time minus 5 seconds
 */
class EventViewAttendancesByEntranceToMQTT extends Receiver{

 /*private $demoDT;  //datetime string
 
 function __construct($demoDateTime){
   
  if(!empty($demoDateTime)){
   $this->demoDT = $demoDateTime;
  } else {
   $this->demoDT = date('Y-m-d H:i:s');
  }
   
 }*/

 public function doExecute(){

  $CI =& get_instance();

  $venueID = 1;
  $eventID = 1;
  
  
  $entAttendances = $CI->Attendances_model->getVenueEntranceAttendances($venueID, $eventID, $this->demoDT);
  $errorArray =  $CI->TktEntitlement_model->getErrorCountByEntrances($eventID,  $this->demoDT);

  $gateAttendances= $this->getGateData($venueID, $eventID);
  
  //var_dump($gateAttendanceData);
  
  
  
  
  //match any error counts to respective attendance counts
  for($i =0; $i< count($entAttendances);$i++){
    
   $entAttendances[$i]['gates'] = array();
   
   for($k =0; $k<count($gateAttendances);$k++){
      if($entAttendances[$i]['ve_id'] === $gateAttendances[$k]['vg_veId'] ){
         array_push($entAttendances[$i]['gates'], $gateAttendances[$k]);
      }
      
      
   }
   
   
   $foundError = false;
    
   for($j=0;$j<count($errorArray); $j++){

    if($entAttendances[$i]['ve_id'] === $errorArray[$j]['ve_id']){
     $entAttendances[$i]['errors'] = $errorArray[$j]['errors'];
     $foundError = true;
     //found errors associated to this token reader so stop looping
     break;
    }
   }
    
   #no error records found so default to 0
   if(!$foundError){
    $entAttendances[$i]['errors'] = "0";
   }
  }
  
  $date = new DateTime();
  $date->modify("-5 second");
  $dateTimeArray = array('date' => $date->format('c'));

  $primaryKey = array('pk' => $this->buildPK($venueID, $eventID));
  

  
  $payloadArray = array_merge(array('ent' => $entAttendances), $dateTimeArray, $primaryKey);
  /*$payloadArray = array();
  for($i=0;$i< 1000;$i++){
   $payloadArray[] = array($i+'asdsakldhasjkgdashgdhsagdhsadghsagdhasgdhasgdhsgkadhasdahk');
  }*/
  
  //var_dump($payloadArray);
  
  $payloadJSON = json_encode($payloadArray);

  
  $eventEntTopic = $this->getEventEntranceTopic($venueID, $eventID);

  /*$client = new Mosquitto\Client();
  $client->connect("10.254.249.24", 1883);

  $client->onConnect('connect');
  $client->onSubscribe('subscribe');
  $client->onMessage('message');
  $client->setMessageRetry(1);
  //$client->onLog('mqttLog');
  $client->onPublish('publish');*/
  $this->client->publish($eventEntTopic, $payloadJSON, 1);
  sleep(1);
  echo "Entrances: \n";

 }
  
 private function getGateData($venueID, $eventID){
  
  $CI =& get_instance();
  
  $attendances = $CI->Attendances_model->getVenueGateAttendances($venueID,$eventID, $this->demoDT);
  $errorArray =  $CI->TktEntitlement_model->getErrorCountByGates($eventID, $this->demoDT);
  
  
  $readerAttendances = $this->getReaderData($venueID, $eventID);
    //match any error counts to respective attendance counts
  for($i =0; $i< count($attendances);$i++){
     
     $attendances[$i]['trs'] = array();
      
     for($k =0; $k< count($readerAttendances);$k++){
      
        //var_dump($attendances[$i]);
       // var_dump($readerAttendances[$k]);
        if($attendances[$i]['vg_id'] === $readerAttendances[$k]['vg_id'] &&  $attendances[$i]['vg_veId'] === $readerAttendances[$k]['ve_id'] ){
         array_push($attendances[$i]['trs'], $readerAttendances[$k]);
        }
     
     
     }
     
     
     $foundError = false;
    
     for($j=0;$j<count($errorArray); $j++){
       
      
      if($attendances[$i]['vg_id'] === $errorArray[$j]['vg_id']){
       $attendances[$i]['errors'] = $errorArray[$j]['errors'];
       $foundError = true;
       //found errors associated to this token reader so stop looping
       break;
      }
     }
    
     #no error records found so default to 0
     if(!$foundError){
      $attendances[$i]['errors'] = "0";
     }
   }
   
   return $attendances;
 }
 
 private function getReaderData($venueID, $eventID){
  
  $CI =& get_instance();
  
   $trAttendances = $CI->Attendances_model->getTrAttendances($venueID, $eventID, $this->demoDT);
   $errorArray =  $CI->TktEntitlement_model->getErrorCountByTr($eventID, $this->demoDT);
   
   //match any error counts to respective attendance counts
   for($i =0; $i< count($trAttendances);$i++){
     
      $foundError = false;
      for($j=0;$j<count($errorArray); $j++){
        
        if($trAttendances[$i]['tr_id'] === $errorArray[$j]['tr_id']){
           $trAttendances[$i]['errors'] = $errorArray[$j]['errors'];
           $foundError = true;
           //found errors associated to this token reader so stop looping
           break;
        }
      }
     
      #no error records found so default to 0
      if(!$foundError){
       $trAttendances[$i]['errors'] = "0";
      }
   }
   
   return $trAttendances;
 }
 
 private function getEventEntranceTopic($venueID, $eventID){
  $vStr = "v_".$venueID;
  $eStr = "e_".$eventID;
  return implode('/',array($vStr ,$eStr,'entrances'));
 }
 
 private function buildPK($venueID, $eventID){
  $vStr = "v_".$venueID;
  $eStr = "e_".$eventID;
  return $vStr.'-'.$eStr;
 }

}

/**
 * Class: EventViewAttendancesByGateToMQTT
 *
 * Gets the attendance information per gate and publishes to MQTT broker on +/+/gates
 * Data retrieved from current time minus 5 seconds
 */

class EventViewAttendancesByGateToMQTT extends Receiver{

 /*private $demoDT;  //datetime string
 
 function __construct($demoDateTime){
   
  if(!empty($demoDateTime)){
   $this->demoDT = $demoDateTime;
  } else {
   $this->demoDT = date('Y-m-d H:i:s');
  }
   
 }*/


 public function doExecute(){
   
  $CI =& get_instance();

  $venueID = 1;
  $eventID = 1;

  $attendances = $CI->Attendances_model->getVenueGateAttendances($venueID,$eventID, $this->demoDT);
  $errorArray =  $CI->TktEntitlement_model->getErrorCountByGates($eventID, $this->demoDT);


  //match any error counts to respective attendance counts
  for($i =0; $i< count($attendances);$i++){

   $foundError = false;

   for($j=0;$j<count($errorArray); $j++){
     
    if($attendances[$i]['vg_id'] === $errorArray[$j]['vg_id']){
     $attendances[$i]['errors'] = $errorArray[$j]['errors'];
     $foundError = true;
     //found errors associated to this token reader so stop looping
     break;
    }
   }

   #no error records found so default to 0
   if(!$foundError){
    $attendances[$i]['errors'] = "0";
   }
  }

  $date = new DateTime();
  $date->modify("-5 second");
  $dateTimeArray = array('date' => $date->format('c'));

  $payloadArray = array_merge(array('gates' => $attendances), $dateTimeArray);
  $payloadJSON = json_encode($payloadArray);

  $eventGateTopic = $this->getEventGateTopic($venueID, $eventID);

  /*$client = new Mosquitto\Client();
  $client->connect("10.254.249.24", 1883);

  $client->onConnect('connect');

  $client->onSubscribe('subscribe');
  $client->onMessage('message');
  //$client->onLog('mqttLog');*/
  $this->client->publish($eventGateTopic, $payloadJSON, 1);
  //echo "MQTT message published to: {$eventGateTopic} \n";

   
   
 }

 private function getEventGateTopic($venueID, $eventID){

  $vStr = "v_".$venueID;
  $eStr = "e_".$eventID;
  return implode('/',array($vStr ,$eStr,'gates'));
 }
}


/**
 * Class: EventViewAttendancesByTrToMQTT
 *
 * Gets the attendance information per token reader and publishes to MQTT broker on +/+/readers
 * Data retrieved from current time minus 5 seconds
 */
class EventViewAttendancesByTrToMQTT extends Receiver{
 /*
 private $demoDT;  //datetime string
 
 function __construct($demoDateTime){
   
  if(!empty($demoDateTime)){
   $this->demoDT = $demoDateTime;
  } else {
   $this->demoDT = date('Y-m-d H:i:s');
  }
 
 }
 */
 public function doExecute(){

  $CI =& get_instance();

  $venueID = 1;
  $eventID = 1;
  
  $trAttendances = $CI->Attendances_model->getTrAttendances($venueID, $eventID, $this->demoDT);
  $errorArray =  $CI->TktEntitlement_model->getErrorCountByTr($eventID, $this->demoDT);
  
  //match any error counts to respective attendance counts
  for($i =0; $i< count($trAttendances);$i++){
   
      $foundError = false;
      for($j=0;$j<count($errorArray); $j++){
       
        if($trAttendances[$i]['tr_id'] === $errorArray[$j]['tr_id']){
            $trAttendances[$i]['errors'] = $errorArray[$j]['errors'];
            $foundError = true;
            //found errors associated to this token reader so stop looping 
            break;
         } 
      }
      
      #no error records found so default to 0
      if(!$foundError){
         $trAttendances[$i]['errors'] = "0";
      }
  }
  
  $date = new DateTime();
  $date->modify("-5 second");
  $dateTimeArray = array('date' => $date->format('c'));

  $payloadArray = array_merge(array('tr' => $trAttendances), $dateTimeArray);
  $payloadJSON = json_encode($payloadArray);

  $eventSummaryTopic = $this->getEventTrTopic($venueID, $eventID);

  /*
  $client = new Mosquitto\Client();
  $client->connect("10.254.249.24", 1883);

  $client->onConnect('connect');
  $client->onSubscribe('subscribe');
  $client->onMessage('message');*/
  //$client->onPublish('publish');
  //$client->onLog('mqttLog');
  $this->client->publish($eventSummaryTopic, $payloadJSON, 1);
  //echo "MQTT message published to: {$eventSummaryTopic} \n";

  

 }
  
 private function getEventTrTopic($venueID, $eventID){
  $vStr = "v_".$venueID;
  $eStr = "e_".$eventID;
  return implode('/',array($vStr ,$eStr,'readers'));
 }

}

/**
 * 
 * Command Classes and parent interface
 * Provide additional layer to executing above classes in case we want to implement another feature
 * Eg logging?
 */

interface Command{
 public function execute();
}

class VenueViewAttendancesToMQTTCommand{

 private $receiver;

 public function __construct(Receiver $mqttReceiver){
  $this->receiver = $mqttReceiver;
 }

 public function execute(){
  $this->receiver->doExecute();
 }
}

class EventViewAttendancesByEntranceToMQTTCommand{

 private $receiver;
  
 public function __construct(Receiver $mqttReceiver){
  $this->receiver = $mqttReceiver;
 }
  
 public function execute(){
  $this->receiver->doExecute();
 }
}

class EventViewAttendancesByGateToMQTTCommand{

 private $receiver;
  
 public function __construct(Receiver $mqttReceiver){
  $this->receiver = $mqttReceiver;
 }
  
 public function execute(){
  $this->receiver->doExecute();
 }
}

class VenueViewAttendancesTotalToMQTTCommand{

 private $receiver;

 public function __construct(Receiver $mqttReceiver){
  $this->receiver = $mqttReceiver;
 }

 public function execute(){
  $this->receiver->doExecute();
 }
}

class EventViewAttendancesBtTrToMQTTCommand{
 private $receiver;

 public function __construct(Receiver $mqttReceiver){
  $this->receiver = $mqttReceiver;
 }

 public function execute(){
  $this->receiver->doExecute();
 }

}

class EventViewSummaryToMQTTCommand{

 private $receiver;

 public function __construct(Receiver $mqttReceiver){
  $this->receiver = $mqttReceiver;
 }

 public function execute(){
  $this->receiver->doExecute();
 }
}

/**
 * 
 * MQTT Class functions taken from Mosquitto-PHP
 * Github: https://github.com/mgdm/Mosquitto-PHP
 */

function connect($r, $message) {
 echo "I got code {$r} and message {$message}\n";
}

function subscribe($topic) {
 //echo "Subscribed to topic\n";
}

function unsubscribe() {
 echo "Unsubscribed from a topic\n";
}

function message($message) {
 printf("Got a message on topic %s\n", $message->topic);
 procmsg( $message->topic, $message->payload);

}

function disconnect() {
 echo "Disconnected cleanly\n";
}

function publish($mid){
 echo "***message published***\n";
}

function mqttLog($level, $str){
 $configFile = APPPATH."../assets/config/error.log";
  
 if(!file_exists($configFile)){
  echo "Error: {$configFile} is not found\n";
  exit();
 }
  
 $configStr = @file_get_contents($configFile);
 
 if($configStr === false){
  $error = error_get_last();
  echo $error['message'];
  exit();
 }
 
 $logMsg = date('Y-m-d H:i:s')." level: {$level}, msg: {$str}\n";
 
 $configStr = $logMsg.$configStr;
 
 $configStr = @file_put_contents($configFile,$configStr );
 if($configStr === false){
  $error = error_get_last();
  echo $error['message'];
  exit();
 }
}

/**
 * Other classes not used 
 */

?>





