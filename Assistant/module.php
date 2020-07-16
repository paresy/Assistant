<?php

declare(strict_types=1);

include_once __DIR__ . '/oauth.php';
include_once __DIR__ . '/simulate.php';
include_once __DIR__ . '/registry.php';
include_once __DIR__ . '/helper/autoload.php';
include_once __DIR__ . '/traits/autoload.php';
include_once __DIR__ . '/types/autoload.php';

class Assistant extends IPSModule
{
    use WebOAuth;
    use Simulate;

    private $registry = null;
    private $apiKey = 'AIzaSyAtQwhb65ITHYJZXd-x7ziBfKkNj5rTo1k';

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);

        $this->registry = new DeviceTypeRegistry(
            $this->InstanceID,
            function ($Name, $Value)
            {
                $this->RegisterPropertyString($Name, $Value);
            },
            function ($Message, $Data, $Format)
            {
                $this->SendDebug($Message, $Data, $Format);
            }
        );
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        if (!IPS_VariableProfileExists('ThermostatMode.GA')) {
            IPS_CreateVariableProfile('ThermostatMode.GA', 1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 0, 'Off', '', -1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 1, 'Heat', '', -1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 2, 'Cool', '', -1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 3, 'On', '', -1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 4, 'HeatCool', '', -1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 5, 'Off', '', -1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 6, 'Off', '', -1);
            IPS_SetVariableProfileAssociation('ThermostatMode.GA', 7, 'Off', '', -1);
        }

        $this->RegisterTimer('ReportStateTimer', 0, 'GA_ReportState($_IPS[\'TARGET\']);');

        //Each accessory is allowed to register properties for persistent data
        $this->registry->registerProperties();

        $this->RegisterPropertyBoolean('EmulateStatus', false);

        $this->RegisterPropertyBoolean('EnableReportState', true);
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->RegisterOAuth('google_smarthome');

        // We need to check for IDs that are empty and assign a proper ID
        $this->registry->updateProperties();

        // Delay sync until KR_READY is reached or we will cause a deadlock
        // Sync on startup is relevant as we need to update the status
        if (IPS_GetKernelRunlevel() == KR_READY) {
            // RequestSync updates the status as well
            $this->RequestSync();
        }

        $objectIDs = $this->registry->getObjectIDs();

        if (method_exists($this, 'GetReferenceList')) {
            $refs = $this->GetReferenceList();
            foreach ($refs as $ref) {
                $this->UnregisterReference($ref);
            }

            foreach ($objectIDs as $id) {
                $this->RegisterReference($id);
            }
        }

        foreach ($this->GetMessageList() as $variableID => $messages) {
            $this->UnregisterMessage($variableID, VM_UPDATE);
        }

        foreach ($objectIDs as $variableID) {
            if (IPS_VariableExists($variableID)) {
                $this->RegisterMessage($variableID, VM_UPDATE);
            }
        }

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $messageID, $data)
    {
        switch ($messageID) {
            case VM_UPDATE:
                //Only transmit report state on changed values and if reporting is enabled
                if ($data[1] && $this->ReadPropertyBoolean('EnableReportState')) {
                    $variableUpdateSemaphore = IPS_SemaphoreEnter('VariableUpdateSemaphore', 500);
                    if ($variableUpdateSemaphore) {
                        $currentVariableUpdatesString = $this->GetBuffer('VariableUpdates');
                        $currentVariableUpdates = ($currentVariableUpdatesString == '') ? [] : json_decode($currentVariableUpdatesString, true);
                        $currentVariableUpdates[] = $senderID;
                        $this->SetBuffer('VariableUpdates', json_encode($currentVariableUpdates));
                        IPS_SemaphoreLeave('VariableUpdateSemaphore');
                        $this->SetTimerInterval('ReportStateTimer', 1000);
                    } else {
                        $this->LogMessage($this->Translate('Variable Update Semaphore is unavailable'), KL_ERROR);
                    }
                }
                break;

            case IPS_KERNELMESSAGE:
                if ($data[0] == KR_READY) {
                    $this->RequestSync();
                }
                break;
        }
    }

    public function ReportState()
    {
        $reportStateSemaphore = IPS_SemaphoreEnter('ReportStateSemaphore', 0);
        if ($reportStateSemaphore) {
            $variableUpdateSemaphore = IPS_SemaphoreEnter('VariableUpdateSemaphore', 50);
            if ($variableUpdateSemaphore) {
                $this->SetTimerInterval('ReportStateTimer', 0);
                $variableUpdates = $this->GetBuffer('VariableUpdates');
                if ($variableUpdates != '') {
                    $this->SetBuffer('VariableUpdates', '');
                    IPS_SemaphoreLeave('VariableUpdateSemaphore');
                    $this->registry->ReportState(json_decode($variableUpdates, true));
                } else {
                    IPS_SemaphoreLeave('VariableUpdateSemaphore');
                }
            }
            IPS_SemaphoreLeave('ReportStateSemaphore');
        }
    }

    public function GetConfigurationForm()
    {
        $expertMode = [
            [
                'type'    => 'PopupButton',
                'caption' => 'Expert Options',
                'popup'   => [
                    'caption' => 'Expert Options',
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Please check the documentation before handling these settings. These settings do not need to be changed under regular circumstances.'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'caption' => 'Emulate Status',
                            'name'    => 'EmulateStatus'
                        ],
                        [
                            'type'    => 'Button',
                            'label'   => 'Request device update',
                            'onClick' => 'GA_RequestSync($id);'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'caption' => 'Transmit state changes to Google',
                            'name'    => 'EnableReportState'
                        ]
                    ]
                ]
            ]
        ];

        $deviceTypes = $this->registry->getConfigurationForm();

        //Check Connect availability
        $ids = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}'); // Connect Control

        // TODO: Rebuild for multiple status, once we have status codes that don't run ApplyChanges regularly
        $inactiveMessage = 'License is not yet linked with Google Assistant!';
        if ((count($ids) < 1) || (IPS_GetInstance($ids[0])['InstanceStatus'] != 102)) {
            $inactiveMessage = 'Symcon Connect is not active!';
        }

        return json_encode(['elements'      => array_merge($deviceTypes, $expertMode),
            'translations'                  => $this->registry->getTranslations(),
            'status'                        => [
                [
                    'code'    => 102,
                    'icon'    => 'active',
                    'caption' => 'Symcon Connect is OK!'
                ],
                [
                    'code'    => 104,
                    'icon'    => 'inactive',
                    'caption' => $inactiveMessage
                ],
                [
                    'code'    => 200,
                    'icon'    => 'error',
                    'caption' => 'The connection to your Google Home Account was lost. Reconnect to Symcon by opening your Google Home app, clicking the Symcon service, and selecting "Search for devices"'
                ]
            ]]);
    }

    public function RequestSync()
    {
        //Check Connect availability
        $ids = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}'); // Connect Control

        if ((count($ids) < 1) || (IPS_GetInstance($ids[0])['InstanceStatus'] != 102)) {
            $this->SetStatus(104);
            if (method_exists($this, 'ReloadForm')) {
                $this->ReloadForm();
            }
            return;
        } else {
            $this->SetStatus(102);
        }
        $data = json_encode([
            'agentUserId' => md5(IPS_GetLicensee())
        ]);

        $result = @file_get_contents('https://homegraph.googleapis.com/v1/devices:requestSync?key=' . $this->apiKey, false, stream_context_create([
            'http' => [
                'method'           => 'POST',
                'header'           => "Content-type: application/json\r\nConnection: close\r\nContent-length: " . strlen($data) . "\r\n",
                'content'          => $data,
                'ignore_errors'    => true
            ],
        ]));

        if ($result === false) {
            echo "Request Sync Failed: \n" . json_encode(error_get_last());
        } elseif (json_decode($result, true) !== []) {
            $this->SendDebug('Request Sync Failed', $result, 0);
            $decode = json_decode($result, true);
            if (isset($decode['error']['message'])) {
                switch ($decode['error']['message']) {
                    case 'Requested entity was not found.':
                        $this->SetStatus(104);
                        if (method_exists($this, 'ReloadForm')) {
                            $this->ReloadForm();
                        }
                        break;

                    case 'The caller does not have permission':
                        $this->SetStatus(200);
                        break;

                    default:
                        echo "Request Sync Failed: \n" . $decode['error']['message'];
                        break;
                }
            } else {
                echo 'Request Sync Failed!';
            }
        }
    }

    protected function ProcessData(array $data): array
    {
        $this->SendDebug('Request', json_encode($data), 0);

        // If we receive a message, then everything must be fine
        $this->SetStatus(102);

        //Redirect errors to our variable to push them into Debug
        ob_start();

        try {
            $result = $this->ProcessRequest($data);
        } catch (Exception $e) {
            $result = [
                'errorCode'   => 'protocolError',
                'debugString' => $e->getMessage()
            ];
        }
        $error = ob_get_contents();
        if ($error != '') {
            $this->SendDebug('Error', $error, 0);
        }
        ob_end_clean();

        $this->SendDebug('Response', json_encode($result), 0);

        return $result;
    }

    protected function ProcessOAuthData()
    {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        $result = $this->ProcessData($data);
        echo json_encode($result);
    }

    private function ProcessSync(): array
    {
        return [
            'agentUserId' => md5(IPS_GetLicensee()),
            'devices'     => $this->registry->doSyncDevices()
        ];
    }

    private function ProcessQuery($payload): array
    {
        if (!isset($payload['devices'])) {
            throw new Exception('devices is undefined');
        }
        if (!is_array($payload['devices'])) {
            throw new Exception('devices is malformed');
        }
        $devices = [];
        foreach ($payload['devices'] as $device) {
            if (!isset($device['id'])) {
                throw new Exception('id is undefined');
            }
            $devices[$device['id']] = $this->registry->doQueryDevice($device['id']);
        }
        return [
            'devices' => $devices
        ];
    }

    private function ProcessExecute($payload): array
    {
        if (!isset($payload['commands'])) {
            throw new Exception('commands is undefined');
        }
        if (!is_array($payload['commands'])) {
            throw new Exception('commands is malformed');
        }
        $results = [];

        foreach ($payload['commands'] as $command) {
            if (!isset($command['devices'])) {
                throw new Exception('devices is undefined');
            }
            if (!is_array($command['devices'])) {
                throw new Exception('devices is malformed');
            }
            if (!isset($command['execution'])) {
                throw new Exception('execution is undefined');
            }
            if (!is_array($command['execution'])) {
                throw new Exception('execution is malformed');
            }
            //Execute each executions command for each device
            foreach ($command['execution'] as $execute) {
                foreach ($command['devices'] as $device) {
                    $this->SendDebug('Execute - ID', $device['id'], 0);
                    $this->SendDebug('Execute - Command', $execute['command'], 0);
                    $this->SendDebug('Execute - Params', json_encode($execute['params']), 0);
                    $results[] = $this->registry->doExecuteDevice($device['id'], $execute['command'], $execute['params']);
                }
            }
        }

        //Merge results into Google's result format
        $commands = [];

        $this->SendDebug('Results', json_encode($results), 0);
        foreach ($results as $result) {
            $found = false;
            foreach ($commands as &$command) {
                //lets assume for now there can only be one result per state
                if ($command['states'] == $result['states']) {
                    $command['ids'] = array_merge($command['ids'], $result['ids']);
                    $found = true;
                }
            }
            if (!$found) {
                $commands[] = $result;
            }
        }

        return [
            'commands' => $commands
        ];
    }

    //See: https://developers.google.com/actions/smarthome/create-app
    private function ProcessRequest($request): array
    {
        if (!isset($request['requestId'])) {
            throw new Exception('requestId is undefined');
        }
        if (!isset($request['inputs'])) {
            throw new Exception('inputs is undefined');
        }
        if (!is_array($request['inputs']) || count($request['inputs']) != 1) {
            throw new Exception('inputs is malformed');
        }
        //Google has defined an array but ony sends one value!
        $input = $request['inputs'][0];

        switch ($input['intent']) {
            case 'action.devices.SYNC':
                $payload = $this->ProcessSync();
                break;
            case 'action.devices.QUERY':
                $payload = $this->ProcessQuery($input['payload']);
                break;
            case 'action.devices.EXECUTE':
                $payload = $this->ProcessExecute($input['payload']);
                break;
            default:
                throw new Exception('Invalid intent');
        }

        return [
            'requestId' => $request['requestId'],
            'payload'   => $payload
        ];
    }
}
