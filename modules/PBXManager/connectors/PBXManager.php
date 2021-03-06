<?php
/* +***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 * *********************************************************************************** */

require_once 'include/database/PearDatabase.php';
require_once 'include/utils/CommonUtils.php';
require_once 'include/fields/DateTimeField.php';
require_once 'include/fields/DateTimeRange.php';
require_once 'include/fields/CurrencyField.php';
require_once 'include/CRMEntity.php';
include_once 'modules/Vtiger/CRMEntity.php';
require_once 'include/runtime/Cache.php';
require_once 'modules/Vtiger/helpers/Util.php';
require_once 'modules/PickList/DependentPickListUtils.php';
require_once 'modules/Users/Users.php';
require_once 'include/Webservices/Utils.php';

class PBXManager_PBXManager_Connector
{
	private static $SETTINGS_REQUIRED_PARAMETERS = ['webappurl' => 'text', 'outboundcontext' => 'text', 'outboundtrunk' => 'text', 'vtigersecretkey' => 'text'];
	private static $RINGING_CALL_PARAMETERS = ['From' => 'callerIdNumber', 'SourceUUID' => 'callUUID', 'Direction' => 'Direction'];
	private static $NUMBERS = [];
	private $webappurl;
	private $outboundcontext;
	private $outboundtrunk;
	private $vtigersecretkey;

	const RINGING_TYPE = 'ringing';
	const ANSWERED_TYPE = 'answered';
	const HANGUP_TYPE = 'hangup';
	const RECORD_TYPE = 'record';
	const INCOMING_TYPE = 'inbound';
	const OUTGOING_TYPE = 'outbound';
	const USER_PHONE_FIELD = 'phone_crm_extension';

	public function __construct()
	{
		$serverModel = PBXManager_Server_Model::getInstance();
		$this->setServerParameters($serverModel);
	}

	/**
	 * Function to get provider name
	 * returns string.
	 */
	public function getGatewayName()
	{
		return 'PBXManager';
	}

	public function getPicklistValues($field)
	{
	}

	public function getServer()
	{
		return $this->webappurl;
	}

	public function getOutboundContext()
	{
		return $this->outboundcontext;
	}

	public function getOutboundTrunk()
	{
		return $this->outboundtrunk;
	}

	public function getVtigerSecretKey()
	{
		return $this->vtigersecretkey;
	}

	public function getXmlResponse()
	{
		header('Content-type: text/xml; charset=utf-8');
		$response = '<?xml version="1.0" encoding="utf-8"?>';
		$response .= '<Response><Authentication>';
		$response .= 'Failure';
		$response .= '</Authentication></Response>';

		return $response;
	}

	/**
	 * Function to set server parameters.
	 *
	 * @param \PBXManager_Server_Model $serverModel
	 */
	public function setServerParameters(\PBXManager_Server_Model $serverModel)
	{
		$this->webappurl = $serverModel->get('webappurl');
		$this->outboundcontext = $serverModel->get('outboundcontext');
		$this->outboundtrunk = $serverModel->get('outboundtrunk');
		$this->vtigersecretkey = $serverModel->get('vtigersecretkey');
	}

	/**
	 * Function to get Settings edit view params
	 * returns <array>.
	 */
	public static function getSettingsParameters()
	{
		return self::$SETTINGS_REQUIRED_PARAMETERS;
	}

	/**
	 * Function prepares parameters.
	 *
	 * @param \App\Request $details
	 * @param string       $type
	 *
	 * @return string
	 */
	protected function prepareParameters(\App\Request $details, $type)
	{
		switch ($type) {
			case 'ringing':
				foreach (self::$RINGING_CALL_PARAMETERS as $key => $value) {
					$params[$key] = $details->get($value);
				}
				$params['GateWay'] = $this->getGatewayName();
				break;
		}
		return $params;
	}

	/**
	 * Function to handle the dial call event.
	 *
	 * @param \App\Request $details
	 */
	public function handleDialCall(\App\Request $details)
	{
		$callid = $details->get('callUUID');

		$answeredby = $details->get('callerid2');
		$caller = $details->get('callerid1');

		// For Inbound call, answered by will be the user, we should fill the user field
		$recordModel = PBXManager_Record_Model::getInstanceBySourceUUID($callid);
		$direction = $recordModel->get('direction');
		if ($direction == self::INCOMING_TYPE) {
			// For Incoming call, we should fill the user field if he answered that call
			$user = PBXManager_Record_Model::getUserInfoWithNumber($answeredby);
			$params['user'] = $user['id'];
			$recordModel->updateAssignedUser($user['id']);
		} else {
			$user = PBXManager_Record_Model::getUserInfoWithNumber($caller);
			if ($user) {
				$params['user'] = $user['id'];
				$recordModel->updateAssignedUser($user['id']);
			}
		}

		$params['callstatus'] = 'in-progress';
		$recordModel->updateCallDetails($params);
	}

	/**
	 * Function to handle the EndCall event.
	 *
	 * @param \App\Request $details
	 */
	public function handleEndCall(\App\Request $details)
	{
		$callid = $details->get('callUUID');
		$recordModel = PBXManager_Record_Model::getInstanceBySourceUUID($callid);

		$params['starttime'] = $details->get('starttime');
		$params['endtime'] = $details->get('endtime');
		$params['totalduration'] = $details->get('duration');
		$params['billduration'] = $details->get('billableseconds');

		$recordModel->updateCallDetails($params);
	}

	/**
	 * Function to handle the hangup call event.
	 *
	 * @param \App\Request $details
	 */
	public function handleHangupCall(\App\Request $details)
	{
		$callid = $details->get('callUUID');
		$recordModel = PBXManager_Record_Model::getInstanceBySourceUUID($callid);
		$hangupcause = $details->get('causetxt');

		switch ($hangupcause) {
			// If call is successfull
			case 'Normal Clearing':
				$params['callstatus'] = 'completed';
				if ($details->get('HangupCause') == 'NO ANSWER') {
					$params['callstatus'] = 'no-answer';
				}
				break;
			case 'User busy':
				$params['callstatus'] = 'busy';
				break;
			case 'Call Rejected':
				$params['callstatus'] = 'busy';
				break;
			default:
				$params['callstatus'] = $hangupcause;
				break;
		}

		if ($details->get('EndTime') && $details->get('Duration')) {
			$params['endtime'] = $details->get('EndTime');
			$params['totalduration'] = $details->get('Duration');
		}

		$recordModel->updateCallDetails($params);
	}

	/**
	 * Function to handle record event.
	 *
	 * @param \App\Request $details
	 */
	public function handleRecording(\App\Request $details)
	{
		$callid = $details->get('callUUID');
		$recordModel = PBXManager_Record_Model::getInstanceBySourceUUID($callid);
		$params['recordingurl'] = $details->get('recordinglink');
		$recordModel->updateCallDetails($params);
	}

	/**
	 * Function to handle AGI event.
	 *
	 * @param \App\Request $details
	 */
	public function handleStartupCall(\App\Request $details, $userInfo, $customerInfo)
	{
		$params = $this->prepareParameters($details, self::RINGING_TYPE);
		$direction = $details->get('Direction');

		// To add customer and user information in params
		$params['Customer'] = $customerInfo['id'];
		$params['CustomerType'] = $customerInfo['setype'];
		$params['User'] = $userInfo['id'];

		if ($details->get('from')) {
			$params['CustomerNumber'] = $details->get('from');
		} elseif ($details->get('to')) {
			$params['CustomerNumber'] = $details->get('to');
		}

		$params['starttime'] = $details->get('StartTime');
		$params['callstatus'] = 'ringing';
		$recordModel = PBXManager_Record_Model::getCleanInstance();
		$recordModel->saveRecordWithArrray($params);

		if ($direction == self::INCOMING_TYPE) {
			$this->respondToIncomingCall($details);
		} else {
			$this->respondToOutgoingCall($params['CustomerNumber']);
		}
	}

	/**
	 * Function to respond for incoming calls.
	 *
	 * @param \App\Request $details
	 */
	public function respondToIncomingCall(\App\Request $details)
	{
		self::$NUMBERS = PBXManager_Record_Model::getUserNumbers();

		header('Content-type: text/xml; charset=utf-8');
		$response = '<?xml version="1.0" encoding="utf-8"?>';
		$response .= '<Response><Dial><Authentication>';
		$response .= 'Success</Authentication>';

		if (self::$NUMBERS) {
			foreach (self::$NUMBERS as $userId => $number) {
				$callPermission = \App\Privilege::isPermitted('PBXManager', 'ReceiveIncomingCalls');

				if ($number != $details->get('callerIdNumber') && $callPermission) {
					if (preg_match('/sip/', $number) || preg_match('/@/', $number)) {
						$number = trim($number, '/sip:/');
						$response .= '<Number>SIP/';
						$response .= $number;
						$response .= '</Number>';
					} else {
						$response .= '<Number>SIP/';
						$response .= $number;
						$response .= '</Number>';
					}
				}
			}
		} else {
			$response .= '<ConfiguredNumber>empty</ConfiguredNumber>';
			$date = date('Y/m/d H:i:s');
			$params['callstatus'] = 'no-answer';
			$params['starttime'] = $date;
			$params['endtime'] = $date;
			$recordModel = PBXManager_Record_Model::getInstanceBySourceUUID($details->get('callUUID'));
			$recordModel->updateCallDetails($params);
		}
		$response .= '</Dial></Response>';
		echo $response;
	}

	/**
	 * Function to respond for outgoing calls.
	 *
	 * @param \App\Request $details
	 */
	public function respondToOutgoingCall($to)
	{
		header('Content-type: text/xml; charset=utf-8');
		$response = '<?xml version="1.0" encoding="utf-8"?>';
		$response .= '<Response><Dial><Authentication>';
		$response .= 'Success</Authentication>';
		$numberLength = strlen($to);

		if (preg_match('/sip/', $to) || preg_match('/@/', $to)) {
			$to = trim($to, '/sip:/');
			$response .= '<Number>SIP/';
			$response .= $to;
			$response .= '</Number>';
		} else {
			$response .= '<Number>SIP/';
			$response .= $to;
			if ($numberLength > 5) {
				$response .= '@' . $this->getOutboundTrunk();
			}
			$response .= '</Number>';
		}

		$response .= '</Dial></Response>';
		echo $response;
	}

	/**
	 * Function to make outbound call.
	 *
	 * @param string $number (Customer)
	 *
	 * @return bool
	 */
	public function call($number)
	{
		$user = Users_Record_Model::getCurrentUserModel();
		$serviceURL = $this->getServer();
		$serviceURL .= '/makecall?event=OutgoingCall&';
		$serviceURL .= 'secret=' . urlencode($this->getVtigerSecretKey()) . '&';
		$serviceURL .= 'from=' . urlencode($user->phone_crm_extension) . '&';
		$serviceURL .= 'to=' . urlencode($number) . '&';
		$serviceURL .= 'context=' . urlencode($this->getOutboundContext());
		$content = '';
		try {
			$response = (new \GuzzleHttp\Client())->request('POST', $serviceURL, ['timeout' => 5, 'connect_timeout' => 1]);
			if ($response->getStatusCode() !== 200) {
				\App\Log::warning("Error when make call: $serviceURL | Status code: " . $response->getStatusCode(), __CLASS__);
				return false;
			}
			$content = trim($response->getBody());
		} catch (\Throwable $exc) {
			\App\Log::warning("Error when make call: $serviceURL | " . $exc->getMessage(), __CLASS__);
			return false;
		}
		if ($content === 'Error' || $content === '' || $content === null || $content === 'Authentication Failure') {
			return false;
		}
		return true;
	}
}
