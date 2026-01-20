<?php

declare(strict_types=1);

// CLASS KnxControlsSonos
class KnxControlsSonos extends IPSModule
{
    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Properties für Form-Felder (Speichern der Benutzer-Auswahl)
        $this->RegisterPropertyInteger('SelectSonosInstanz', 0);
        
        // Neue Listen-Properties
        $this->RegisterPropertyString('PlayPauseInputs', '[]');
        $this->RegisterPropertyString('MuteInputs', '[]');
        $this->RegisterPropertyString('VolumeInputs', '[]');
        $this->RegisterPropertyString('RelativeVolumeInputs', '[]');
        $this->RegisterPropertyString('SceneInputs', '[]');
        $this->RegisterPropertyInteger('KNX_StatusFeedback', 0);
        $this->RegisterPropertyInteger('KNX_MuteFeedback', 0);
        $this->RegisterPropertyInteger('KNX_VolumeFeedback', 0);

        $this->RegisterPropertyInteger('StepSize', 4);
        $this->RegisterPropertyString("StationMapping", "[]");
        $this->RegisterPropertyString('SelectedStation', "");
        $this->RegisterPropertyString("UrlMapping", "[]");
        
        // Register timer for periodic status updates (every second)
        $this->RegisterTimer('UpdateStatus', 1000, 'KCS_UpdateStatus(' . $this->InstanceID . ');');
        $this->RegisterTimer('Block_Volume', 0, 'KCS_Block_Volume(' . $this->InstanceID . ');');

        // Initialize buffer for volume blocking
        $this->SetBuffer("Block_Volume", "false");
        $this->SetBuffer("DimDirection", "1"); // 1 = Up, 0 = Down
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     */
    public function Destroy()
    {
        parent::Destroy();
    }


    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Set status
        $this->SetStatus(102);

        // Start timer for periodic updates
        $this->SetTimerInterval('UpdateStatus', 0);

        // Unregister all previous messages for safety
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Register Play/Pause Inputs
        $playPauseInputs = json_decode($this->ReadPropertyString("PlayPauseInputs"), true);
        foreach ($playPauseInputs as $input) {
            $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
            if ($id > 0 && IPS_VariableExists($id)) $this->RegisterMessage($id, VM_UPDATE);
        }

        // Register Mute Inputs
        $muteInputs = json_decode($this->ReadPropertyString("MuteInputs"), true);
        foreach ($muteInputs as $input) {
            $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
            if ($id > 0 && IPS_VariableExists($id)) $this->RegisterMessage($id, VM_UPDATE);
        }

        // Register Volume Inputs (Absolute)
        $volumeInputs = json_decode($this->ReadPropertyString("VolumeInputs"), true);
        foreach ($volumeInputs as $input) {
            $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
            if ($id > 0 && IPS_VariableExists($id)) $this->RegisterMessage($id, VM_UPDATE);
        }

        // Register Relative Volume Inputs (Dimming)
        $relVolInputs = json_decode($this->ReadPropertyString("RelativeVolumeInputs"), true);
        foreach ($relVolInputs as $input) {
            $stepStopID = isset($input['StepStopVariable']) ? (int)$input['StepStopVariable'] : 0;
            // Wir registrieren nur Step/Stop, da die Richtung nur beim Starten gelesen wird
            if ($stepStopID > 0 && IPS_VariableExists($stepStopID)) $this->RegisterMessage($stepStopID, VM_UPDATE);
        }

        // Register Scene Inputs
        $sceneInputs = json_decode($this->ReadPropertyString("SceneInputs"), true);
        foreach ($sceneInputs as $input) {
            $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
            if ($id > 0 && IPS_VariableExists($id)) $this->RegisterMessage($id, VM_UPDATE);
        }

        // Register messages for selected Sonos instance variables
        $sonosID  = $this->ReadPropertyInteger("SelectSonosInstanz");
        if ($sonosID > 0) {
            $Idents = [
                'Volume',
                'nowPlaying',
                'Status',
                'Mute'
            ];
            foreach ($Idents as $ident) {
                $objectID = IPS_GetObjectIDByIdent($ident, $sonosID);
                $this->RegisterMessage($objectID, VM_UPDATE);
            }   
        }

    }
    /**
     * The content of the function can be overwritten in order to carry out own reactions to certain messages.
     * The function is only called for registered MessageIDs/SenderIDs combinations.
     *
     * data[0] = new value
     * data[1] = value changed?
     * data[2] = old value
     * data[3] = timestamp.
     *
     * @param int   $timestamp Continuous counter timestamp
     * @param int   $sender    Sender ID
     * @param int   $message   ID of the message
     * @param array{0:mixed,1:bool,2:mixed,3:int} $data Data of the message
     */
    public function MessageSink($timestamp, $sender, $message, $data)
    {
        // Check for update messages is a Variable Update
        if ($message !== VM_UPDATE) {
            return;
        }
        //$this->SendDebug(__FUNCTION__, "Update: " . $sender , 0);
        
        // Get Sonos instance variable IDs
        $sonosID = $this->ReadPropertyInteger('SelectSonosInstanz');
        $sonosVolumeID = ($sonosID > 0) ? IPS_GetObjectIDByIdent('Volume', $sonosID) : 0;
        $sonosNowPlayingID = ($sonosID > 0) ? IPS_GetObjectIDByIdent('nowPlaying', $sonosID) : 0;
        $sonosStatusID = ($sonosID > 0) ? IPS_GetObjectIDByIdent('Status', $sonosID) : 0;
        $sonosMuteID = ($sonosID > 0) ? IPS_GetObjectIDByIdent('Mute', $sonosID) : 0;

        $handled = false;

        // 1. Check Play/Pause Inputs
        $playPauseInputs = json_decode($this->ReadPropertyString("PlayPauseInputs"), true);
        foreach ($playPauseInputs as $input) {
            $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
            if ($sender == $id) {
                $this->HandlePlayPauseKnxSonos($data[0]);
                $handled = true;
                break;
            }
        }

        // 2. Check Mute Inputs
        if (!$handled) {
            $muteInputs = json_decode($this->ReadPropertyString("MuteInputs"), true);
            foreach ($muteInputs as $input) {
                $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
                if ($sender == $id) {
                    $this->HandleMuteKnxSonos($data[0]);
                    $handled = true;
                    break;
                }
            }
        }

        // 3. Check Volume Inputs (Absolute)
        if (!$handled) {
            $volumeInputs = json_decode($this->ReadPropertyString("VolumeInputs"), true);
            foreach ($volumeInputs as $input) {
                $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
                if ($sender == $id) {
                    if ($data[1] == true) { // Only on change
                        if ($this->GetBuffer("Block_Volume") != "true") {
                            $this->HandleVolumeKnxSonos($data[0]);
                            // Block Volume changes for 100ms to avoid feedback loop
                            $this->SetBuffer("Block_Volume", "true");
                            $this->SetTimerInterval('Block_Volume', 100);
                        }
                    }
                    $handled = true;
                    break;
                }
            }
        }

        // 4. Check Relative Volume Inputs (Dimming)
        if (!$handled) {
            $relVolInputs = json_decode($this->ReadPropertyString("RelativeVolumeInputs"), true);
            foreach ($relVolInputs as $input) {
                $stepStopID = isset($input['StepStopVariable']) ? (int)$input['StepStopVariable'] : 0;
                if ($sender == $stepStopID) {
                    $directionID = isset($input['DirectionVariable']) ? (int)$input['DirectionVariable'] : 0;
                    $switchDirectionID = isset($input['SwitchDirectionVariable']) ? (int)$input['SwitchDirectionVariable'] : 0;
                    $this->HandleDimmingKnxSonos($data[0], $directionID, $switchDirectionID);
                    $handled = true;
                    break;
                }
            }
        }

        // 5. Check Scene Inputs
        if (!$handled) {
            $sceneInputs = json_decode($this->ReadPropertyString("SceneInputs"), true);
            foreach ($sceneInputs as $input) {
                $id = isset($input['VariableID']) ? (int)$input['VariableID'] : 0;
                if ($sender == $id) {
                    $this->HandleSceneSelectionKnxSonos($data[0]);
                    $handled = true;
                    break;
                }
            }
        }

        if ($handled) return;

        // Switch based on sender ID for Sonos events
        switch ($sender) {
            // Handle Sonos Volume changes
            case $sonosVolumeID:
                if ($data[1] == true) {
                    $this->SendDebug(__FUNCTION__, "Sonos Volume changed to: " . $data[0], 0);
                    $this->UpdateFeedback(1, $data[0]); // 1 = Volume
                    
                }
                break;
            // Handle Sonos Now Playing changes
            case $sonosNowPlayingID:
                if ($data[1] == true) {
                    $this->SendDebug(__FUNCTION__, "Sonos Now Playing changed to: " . $data[0], 0);
                }
                break;

            // Handle Sonos Status changes
            case $sonosStatusID:
                if ($data[1] == true) {
                    $this->SendDebug(__FUNCTION__, "Sonos Status changed to: " . $data[0], 0);
                    $this->UpdateFeedback(0, $data[0]); // 0 = Status
                }
                break;

            // Handle Sonos Mute changes
            case $sonosMuteID:
                if ($data[1] == true) {
                    $this->SendDebug(__FUNCTION__, "Sonos Mute changed to: " . $data[0], 0);
                    $this->UpdateFeedback(2, $data[0]); // 2 = Mute
                }
                break;

            default:
                $this->SendDebug(__FUNCTION__, "Unknown sender: $sender", 0);
                break;
        }
    }

    /**
     * Handle KNX Play/Pause command
     */
    private function HandlePlayPauseKnxSonos($value)
    {
        $sonosID = $this->ReadPropertyInteger('SelectSonosInstanz');
        $statusID = IPS_GetObjectIDByIdent('Status', $sonosID);
        $this->SendDebug(__FUNCTION__, "Play/Pause: value= " . json_encode($value), 0);

        if ($value === true) {
            // Start playing
            $this->SendDebug(__FUNCTION__, "Play Start: $statusID", 0);
            $station = $this->ReadPropertyString('SelectedStation');
            $this->PlayStation($station, $sonosID);
            
            //RequestAction($statusID, 2); // Play
        } else {
            $this->SendDebug(__FUNCTION__, "Pause Start: $statusID", 0);
            // Pause
            RequestAction($statusID, 3);
        }
    }

    /**
     * Handle KNX Mute command
     */
    private function HandleMuteKnxSonos($value)
    {
        $sonosID = $this->ReadPropertyInteger('SelectSonosInstanz');
        if ($sonosID > 0) {
            $muteID = IPS_GetObjectIDByIdent('Mute', $sonosID);
            if (GetValueBoolean($muteID) !== $value) {
                RequestAction($muteID, $value);
                $this->SendDebug(__FUNCTION__, "Set Sonos Mute to: " . ($value ? 'true' : 'false'), 0);
            }
        }
    }

    /**
     * Handle KNX Dimming variable (toggle UpdateStatus timer)
     */
    private function HandleDimmingKnxSonos($value, $directionID, $switchDirectionID)
    {   
        $this->SendDebug(__FUNCTION__, "Dimming: value=$value", 0);
        
        if ($value == 0) {
            // Stop Dimming
            $this->SetTimerInterval('UpdateStatus', 0);
        } else {
            // Start Dimming
            $direction = false; // Default Down
            if ($directionID > 0 && IPS_VariableExists($directionID)) {
                $direction = GetValueBoolean($directionID);
            }
            $this->SetBuffer("DimDirection", $direction ? "1" : "0");

            $this->SetTimerInterval('UpdateStatus', 500);
            
            // Update Switch Direction Feedback (for 1-button dimming)
            if ($switchDirectionID > 0 && IPS_VariableExists($switchDirectionID)){
                RequestAction($switchDirectionID, $direction);
            }
        }
    }
    /**
     * Handle KNX Volume variable
     */
    private function HandleVolumeKnxSonos($value)
    {
        $sonosID = $this->ReadPropertyInteger('SelectSonosInstanz');
        if ($sonosID > 0) {
            $volumeID = IPS_GetObjectIDByIdent('Volume', $sonosID);
            If ($value != GetValueInteger($volumeID)) {
                RequestAction($volumeID, $value);
                $this->SendDebug(__FUNCTION__, "Set Sonos Volume to: $value", 0);
            }   

        }
    }   

    /**
     * Handle KNX Scene Selection variable
     */
    private function HandleSceneSelectionKnxSonos($value)
    {

        $stationMapping = json_decode($this->ReadPropertyString('StationMapping'), true);
        $urlMapping = json_decode($this->ReadPropertyString('UrlMapping'), true);
        $sonosID = $this->ReadPropertyInteger('SelectSonosInstanz');

        // Check if Sonos Box is part of a group
        $groupID = IPS_GetObjectIDByIdent('MemberOfGroup', $sonosID);
        $group = GetValueInteger($groupID);
        $this->SendDebug(__FUNCTION__, "Sonos Box is part of a group: " .$group, 0);

        if ($group == 0) {

            $this->SendDebug(__FUNCTION__, "Scene mapping: value= $value", 0);
            $selectedStation = "";

            foreach ($stationMapping as $mapping) {
                $stationNumber = $mapping['StationNumber'];
                if ($stationNumber == $value) {
                    $selectedStation = $mapping['StationName'];
                    break;
                }
            }
            $selectedUrl = "";
            $VolumeChange = "";
            foreach ($urlMapping as $mapping) {
                $urlNumber = $mapping['UrlNumber'];
                if ($urlNumber == $value) {
                    $selectedUrl = $mapping['FileUrl'];
                    $VolumeChange = $mapping['VolumeChnge'];
                    break;
                }
            }
            


            if (!empty($selectedStation) and ($sonosID > 0  )) {
                $this->SendDebug(__FUNCTION__, "Mapped to Station: $selectedStation ", 0);
                $this->PlayStation($selectedStation, $this->ReadPropertyInteger('SelectSonosInstanz'));
            }elseif (!empty($selectedUrl)) {

                $this->SendDebug(__FUNCTION__, "Mapped to File URL: $selectedUrl Changes Volume: $VolumeChange", 0);  
                //SNS_PlayFile($sonosID, [$selectedUrl],0);
                $this->SendDebug(__FUNCTION__, "Set Sonos File URL to: $selectedUrl", 0);
                
                // Block Volume changes for 100ms to avoid feedback loop (if volume is changed by file play)
                $this->SetBuffer("Block_Volume", "true");
                SNS_PlayFiles($sonosID, "[\"".$selectedUrl."\"]", $VolumeChange);
                
                // Activate Timer to reset the Volume blocking
                $this->SetTimerInterval('Block_Volume', 2000);
                
                
            } 
            else {
                $this->SendDebug(__FUNCTION__, "No mapping found for Scene Number: $value", 0);
            }
        }

        

    }              

    

    /**
     * Updates KNX Feedback variables based on Sonos status changes.
     * Type: 0=Status (Play/Pause), 1=Volume, 2=Mute
     */
    private function UpdateFeedback(int $type, $value)
    {
        if ($type == 0) { // Status
            $id = $this->ReadPropertyInteger('KNX_StatusFeedback');
            if ($id > 0 && IPS_VariableExists($id)) {
                // Sonos: 1=Playing. Map to KNX Bool (True=Play)
                $isPlaying = ($value == 1); 
                if (GetValue($id) != $isPlaying) {
                    RequestAction($id, $isPlaying);
                }
            }
        } elseif ($type == 1) { // Volume
            $id = $this->ReadPropertyInteger('KNX_VolumeFeedback');
            if ($id > 0 && IPS_VariableExists($id)) {
                if (GetValue($id) != $value) {
                    RequestAction($id, $value);
                }
            }
        } elseif ($type == 2) { // Mute
            $id = $this->ReadPropertyInteger('KNX_MuteFeedback');
            if ($id > 0 && IPS_VariableExists($id)) {
                if (GetValue($id) != $value) {
                    RequestAction($id, $value);
                }
            }
        }
    }

    /**
     * Returns the configuration form as a JSON string.
     *
     * @return string JSON encoded configuration form
     */
    public function GetConfigurationForm() 
    {
        // Load the base form from the form.json file
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        // 1. Read both mapping properties
        $stationMapping = json_decode($this->ReadPropertyString("StationMapping"), true);
        $urlMapping = json_decode($this->ReadPropertyString("UrlMapping"), true);

        // 2. Collect all numbers from both lists into one single array
        $allNumbers = array_merge(
            array_column($stationMapping, 'StationNumber'),
            array_column($urlMapping, 'UrlNumber')
        );

        // 3. Check for duplicates across the combined array
        $hasDuplicates = count($allNumbers) !== count(array_unique($allNumbers));

        // 4. Fetch Sonos profile options
        $options = [];
        if (IPS_VariableProfileExists("SONOS.Playlist")) {
            $profile = IPS_GetVariableProfile("SONOS.Playlist");
            foreach ($profile['Associations'] as $association) {
                $options[] = [
                    "caption" => $association['Name'],
                    "value" => $association['Name']
                ];
            }
        }

        // 5. Iterate through elements to inject warnings and options
        foreach ($form['elements'] as $i => $element) {
            if ($element['type'] == "ExpansionPanel" && $element['name'] == "SettingsPanel") {
                
                $newItems = [];
                foreach ($element['items'] as $item) {
                    
                    // If duplicates are found, inject a global warning before the lists
                    // We check if we are at the first list to place the warning once
                    if (($item['name'] == "StationMapping" || $item['name'] == "UrlMapping") && $hasDuplicates) {
                        // Only add the warning if it's not already there
                        $warningExists = false;
                        foreach ($newItems as $check) {
                            if (isset($check['name']) && $check['name'] == "GlobalDuplicateWarning") $warningExists = true;
                        }

                        if (!$warningExists) {
                            $newItems[] = [
                                "type" => "Label",
                                "name" => "GlobalDuplicateWarning",
                                "caption" => "⚠️ Error: The same number is used multiple times across Station and URL mappings!",
                                "color" => "#FF0000",
                                "bold" => true
                            ];
                        }
                    }

                    // Populate Sonos options for StationMapping
                    if ($item['name'] == "StationMapping") {
                        foreach ($item['columns'] as $k => $column) {
                            if ($column['name'] == "StationName") {
                                $item['columns'][$k]['edit']['options'] = $options;
                            }
                        }
                    }

                    // Populate SelectedStation if needed
                    if ($item['name'] == "SelectedStation") {
                        $item['options'] = $options;
                    }

                    $newItems[] = $item;
                }
                $form['elements'][$i]['items'] = $newItems;
            }
        }

        return json_encode($form);
    }
    
    
    /**
     * Periodic update function called every second via timer as long as dimming is active
     */
    public function UpdateStatus()
    {
        $direction = ($this->GetBuffer("DimDirection") === "1");
        $this->UpdateVolume($direction); // true = Up, false = Down
        $this->SendDebug(__FUNCTION__, "Update of Volume direction: " . json_encode($direction), 0);
    }

    /**
     * Update Volume based on direction
     */
    public function UpdateVolume(bool $direction)
    {
        $sonosID = $this->ReadPropertyInteger('SelectSonosInstanz');
        $stepSize = $this->ReadPropertyInteger('StepSize');
        $VolumeID = IPS_GetObjectIDByIdent('Volume', $sonosID);

        $ActualVolume = GetValueInteger($VolumeID);

        $this->SendDebug(__FUNCTION__, "Old Volume: " . $ActualVolume, 0);

        if ($sonosID > 0) {
            if ($direction == true) {
                // Increase volume
                RequestAction($VolumeID, $ActualVolume + $stepSize);
            } else {
                // Decrease volume
                RequestAction($VolumeID, $ActualVolume - $stepSize);    
            }
        }
    }

    // Play a station by name (use names form SONOS.Playlist profile)
    public function PlayStation(string $stationName, int $sonosID)
    {
        if (!empty($stationName)) {
            $this->SendDebug(__FUNCTION__, "Start Playing Station: $stationName", 0);
            $profile = IPS_GetVariableProfile("SONOS.Playlist");
            $stationNumber = 0;
            foreach ($profile['Associations'] as $association) {
                if ($association['Name'] == $stationName) {
                    $stationNumber = $association['Value'];
                    break;
                }
            }
            if ($stationNumber > 0) {
                $playlistID = IPS_GetObjectIDByIdent('Playlist', $sonosID);
                RequestAction($playlistID, $stationNumber);
                $this->SendDebug(__FUNCTION__, "Start Playing Station Number: $stationNumber", 0);
            }
        }
    
    }

    public function Block_Volume()
    {
        $isLocked = $this->GetBuffer("Block_Volume");
        $this->SendDebug(__FUNCTION__, "Volume is Blockt: " . $isLocked, 0);
        if ($isLocked == "true") {
            $this->SendDebug(__FUNCTION__, "unblock Volume", 0);
            $this->SetBuffer("Block_Volume", "false");
            $this->SetTimerInterval('Block_Volume', 0);
        }
    }

    public function PlayByScene(int $sceneNumber)
    {
        $this->HandleSceneSelectionKnxSonos($sceneNumber);
    }
    
}