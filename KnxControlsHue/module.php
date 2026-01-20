<?php

declare(strict_types=1);

// CLASS KnxControlsHue
class KnxControlsHue extends IPSModule
{
    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Property for the KNX Inputs (List of Scene Number and Control Variables)
        $this->RegisterPropertyString("KnxSceneInputs", "[]");
        
        // Property for the Hue Instance (Target Device)
        $this->RegisterPropertyInteger("HueInstanceID", 0);
        
        // Property for the KNX Switch and Dim Inputs (Separate Lists)
        $this->RegisterPropertyString("KnxSwitchInputs", "[]");
        $this->RegisterPropertyString("KnxDimInputs", "[]");
        $this->RegisterPropertyInteger("DimStep", 8);
        $this->RegisterPropertyInteger("DimInterval", 200);
        
        // Property for the KNX Tunable White Inputs (Absolute Kelvin)
        $this->RegisterPropertyString("KnxTwInputs", "[]");
        
        // Property for the KNX Tunable White Dim Inputs (Relative)
        $this->RegisterPropertyString("KnxTwDimInputs", "[]");
        $this->RegisterPropertyInteger("TwStep", 20);
        $this->RegisterPropertyInteger("TwInterval", 200);

        // Property for the KNX Color Inputs (Absolute RGB)
        $this->RegisterPropertyString("KnxColorInputs", "[]");

        // Mapping Table: KNX Scene Number -> Hue Color/Brightness
        $this->RegisterPropertyString("SceneMapping", "[]");
        $this->RegisterPropertyBoolean("AutoCreateUnknownScenes", true);
        $this->RegisterPropertyBoolean("AutoActivateNewScenes", false);

        // Property for KNX Feedback (Single Variables to report status back to bus)
        $this->RegisterPropertyInteger("KnxFeedbackStatusID", 0);
        $this->RegisterPropertyInteger("KnxFeedbackBrightnessID", 0);
        $this->RegisterPropertyInteger("KnxFeedbackColorID", 0);
        $this->RegisterPropertyInteger("KnxFeedbackColorTemperatureID", 0);

        // Register timer for dimming loop (used for relative dimming)
        $this->RegisterTimer('DimmingTimer', 0, 'KCH_DimLoop(' . $this->InstanceID . ');');
        $this->RegisterTimer('TwDimmingTimer', 0, 'KCH_TwDimLoop(' . $this->InstanceID . ');');
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
     * The content can be overwritten in order to transfer a self-created configuration page.
     * This way, content can be generated dynamically.
     * In this case, the "form.json" on the file system is completely ignored.
     */
    public function GetConfigurationForm()
    {
        // Get Form
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Look for KNX Scene Variable Profile to populate the dropdown in the list
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);
        $options = [];

        // Iterate through all inputs to find a valid profile to use for the dropdown options
        foreach ($knxSceneInputs as $input) {
            $knxSceneID = isset($input['SceneNumberID']) ? (int)$input['SceneNumberID'] : 0;
            if ($knxSceneID > 0 && IPS_VariableExists($knxSceneID)) {
                $variable = IPS_GetVariable($knxSceneID);
                $profileName = $variable['VariableCustomProfile'] ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];

                if ($profileName != "" && IPS_VariableProfileExists($profileName)) {
                    $profile = IPS_GetVariableProfile($profileName);
                    foreach ($profile['Associations'] as $association) {
                        // Filter for valid scene numbers (1-64)
                        if ($association['Value'] >= 1 && $association['Value'] <= 64) {
                            $options[] = [
                                'caption' => $association['Value'] . " - " . $association['Name'],
                                'value'   => $association['Value']
                            ];
                        }
                    }
                    // If we found options, we can stop looking
                    if (count($options) > 0) break;
                }
            }
        }

        // Iterate through form elements to apply dynamic changes (replace NumberSpinner with Select)
        foreach ($form['elements'] as $i => $element) {
            if (isset($element['name']) && $element['name'] == 'SettingsPanel') {
                foreach ($element['items'] as $j => $item) {
                    
                    // 2. Replace NumberSpinner with Select if options exist
                    if (count($options) > 0 && isset($item['name']) && $item['name'] == 'SceneMapping') {
                        foreach ($item['columns'] as $k => $column) {
                            if ($column['name'] == 'KnxNumber') {
                                $form['elements'][$i]['items'][$j]['columns'][$k]['edit'] = [
                                    'type' => 'Select',
                                    'options' => $options
                                ];
                                // Adjust width to fit the text
                                $form['elements'][$i]['items'][$j]['columns'][$k]['width'] = '250px';
                            }
                        }
                    }
                }
            }
        }

        return json_encode($form);
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Unregister all messages to avoid duplicates
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Register messages for all KNX Scene variables in the list
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);
        foreach ($knxSceneInputs as $input) {
            $sceneNumberID = isset($input['SceneNumberID']) ? (int)$input['SceneNumberID'] : 0;
            $sceneControlID = isset($input['ControlID']) ? (int)$input['ControlID'] : 0;

            if ($sceneNumberID > 0 && IPS_VariableExists($sceneNumberID)) {
                $this->RegisterMessage($sceneNumberID, VM_UPDATE);
            }
            if ($sceneControlID > 0 && IPS_VariableExists($sceneControlID)) {
                $this->RegisterMessage($sceneControlID, VM_UPDATE);
            }
        }

        // Register messages for KNX Switch variables (On/Off)
        $knxSwitchInputs = json_decode($this->ReadPropertyString("KnxSwitchInputs"), true);
        foreach ($knxSwitchInputs as $input) {
            $switchID = isset($input['SwitchID']) ? (int)$input['SwitchID'] : 0;
            if ($switchID > 0 && IPS_VariableExists($switchID)) $this->RegisterMessage($switchID, VM_UPDATE);
        }

        // Register messages for KNX Dim variables (Relative Dimming)
        $knxDimInputs = json_decode($this->ReadPropertyString("KnxDimInputs"), true);
        foreach ($knxDimInputs as $input) {
            $dimStepID = isset($input['DimStepID']) ? (int)$input['DimStepID'] : 0;
            $dimDirectionID = isset($input['DimDirectionID']) ? (int)$input['DimDirectionID'] : 0;
            if ($dimStepID > 0 && IPS_VariableExists($dimStepID)) $this->RegisterMessage($dimStepID, VM_UPDATE);
            if ($dimDirectionID > 0 && IPS_VariableExists($dimDirectionID)) $this->RegisterMessage($dimDirectionID, VM_UPDATE);
        }

        // Register messages for KNX Tunable White variables (Absolute Kelvin)
        $knxTwInputs = json_decode($this->ReadPropertyString("KnxTwInputs"), true);
        foreach ($knxTwInputs as $input) {
            $twID = isset($input['TwID']) ? (int)$input['TwID'] : 0;
            if ($twID > 0 && IPS_VariableExists($twID)) $this->RegisterMessage($twID, VM_UPDATE);
        }

        // Register messages for KNX Tunable White Dim variables (Relative)
        $knxTwDimInputs = json_decode($this->ReadPropertyString("KnxTwDimInputs"), true);
        foreach ($knxTwDimInputs as $input) {
            $twStepID = isset($input['TwStepID']) ? (int)$input['TwStepID'] : 0;
            $twDirectionID = isset($input['TwDirectionID']) ? (int)$input['TwDirectionID'] : 0;
            if ($twStepID > 0 && IPS_VariableExists($twStepID)) $this->RegisterMessage($twStepID, VM_UPDATE);
            if ($twDirectionID > 0 && IPS_VariableExists($twDirectionID)) $this->RegisterMessage($twDirectionID, VM_UPDATE);
        }

        // Register messages for KNX Color variables (Absolute RGB)
        $knxColorInputs = json_decode($this->ReadPropertyString("KnxColorInputs"), true);
        foreach ($knxColorInputs as $input) {
            $colorID = isset($input['ColorID']) ? (int)$input['ColorID'] : 0;
            if ($colorID > 0 && IPS_VariableExists($colorID)) $this->RegisterMessage($colorID, VM_UPDATE);
        }

        // Register messages for Hue Instance variables to trigger Feedback on KNX Bus
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
        if ($hueInstanceID > 0) {
            // We need to listen to Hue changes to update KNX Feedback variables
            $idents = ["on", "brightness", "color", "color_temperature"];
            foreach ($idents as $ident) {
                $id = @IPS_GetObjectIDByIdent($ident, $hueInstanceID);
                if ($id !== false) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
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
    public function MessageSink($timestamp, $SenderID, $message, $data)
    {
        $this->SendDebug(__FUNCTION__, "New Value: " .$SenderID. " : ". $data[0], 0);
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);
        $handled = false;
    
        // Check Scene Inputs
        foreach ($knxSceneInputs as $input) {
            $sceneNumberID = isset($input['SceneNumberID']) ? (int)$input['SceneNumberID'] : 0;
            $sceneControlID = isset($input['ControlID']) ? (int)$input['ControlID'] : 0;

            // Handle Scene Number (Call or Save based on Control variable state)
            if ($SenderID == $sceneNumberID) {
                $this->SendDebug(__FUNCTION__, "New KNX Scene Number: " . $data[0], 0);
                
                // Check if we are in Save Mode
                $isSaveMode = false;
                if ($sceneControlID > 0 && IPS_VariableExists($sceneControlID)) {
                    $isSaveMode = GetValueBoolean($sceneControlID);
                }

                if ($isSaveMode) {
                    $this->SaveHueScene((int)$data[0]);
                } else {
                    $this->CallHueScene((int)$data[0]);
                }
                $handled = true;
                break;
            }

            // Handle Scene Control (Save current scene if value is true)
            if ($SenderID == $sceneControlID) {
                $this->SendDebug(__FUNCTION__, "KNX Scene Control Triggered: " . json_encode($data[0]), 0);
                
                // If Control is True (Save), save current scene
                if ($data[0] == true) {
                    if ($sceneNumberID > 0 && IPS_VariableExists($sceneNumberID)) {
                        $sceneNumber = GetValueInteger($sceneNumberID);
                        if ($sceneNumber >= 1) {
                            $this->SaveHueScene($sceneNumber);
                        }
                    }
                }
                $handled = true;
                break;
            }
        }

        if (!$handled) {
            // Check Switch Inputs (On/Off)
            $knxSwitchInputs = json_decode($this->ReadPropertyString("KnxSwitchInputs"), true);
            foreach ($knxSwitchInputs as $input) {
                $switchID = isset($input['SwitchID']) ? (int)$input['SwitchID'] : 0;
                if ($SenderID == $switchID) {
                    $this->SendDebug(__FUNCTION__, "KNX Switch Triggered: " . json_encode($data[0]), 0);
                    $this->HandleSwitch((bool)$data[0]);
                    $handled = true;
                    break;
                }
            }
        }

        if (!$handled) {
            // Check Dim Inputs (Relative Dimming)
            $knxDimInputs = json_decode($this->ReadPropertyString("KnxDimInputs"), true);
            foreach ($knxDimInputs as $input) {
                $dimStepID = isset($input['DimStepID']) ? (int)$input['DimStepID'] : 0;
                $dimDirectionID = isset($input['DimDirectionID']) ? (int)$input['DimDirectionID'] : 0;
                $dimSwitchDirectionID = isset($input['DimSwitchDirectionID']) ? (int)$input['DimSwitchDirectionID'] : 0;

                if ($SenderID == $dimStepID) {
                    $this->SendDebug(__FUNCTION__, "KNX Dimming Triggered: " . json_encode($data[0]), 0);
                    $this->HandleDimming((int)$data[0], $dimDirectionID, $dimSwitchDirectionID);
                    $handled = true;
                    break;
                } elseif ($SenderID == $dimDirectionID) {
                    $this->SendDebug(__FUNCTION__, "KNX Dimming Direction Changed: " . json_encode($data[0]), 0);
                    $handled = true;
                    break;
                }
            }
        }

        if (!$handled) {
            // Check TW Inputs (Absolute Kelvin)
            $knxTwInputs = json_decode($this->ReadPropertyString("KnxTwInputs"), true);
            foreach ($knxTwInputs as $input) {
                $twID = isset($input['TwID']) ? (int)$input['TwID'] : 0;
                if ($SenderID == $twID) {
                    $this->SendDebug(__FUNCTION__, "KNX Color Temperature Triggered: " . json_encode($data[0]), 0);
                    $this->HandleColorTemperature((int)$data[0]);
                    $handled = true;
                    break;
                }
            }
        }

        if (!$handled) {
            // Check TW Dim Inputs (Relative)
            $knxTwDimInputs = json_decode($this->ReadPropertyString("KnxTwDimInputs"), true);
            foreach ($knxTwDimInputs as $input) {
                $twStepID = isset($input['TwStepID']) ? (int)$input['TwStepID'] : 0;
                $twDirectionID = isset($input['TwDirectionID']) ? (int)$input['TwDirectionID'] : 0;
                $twSwitchDirectionID = isset($input['TwSwitchDirectionID']) ? (int)$input['TwSwitchDirectionID'] : 0;

                if ($SenderID == $twStepID) {
                    $this->SendDebug(__FUNCTION__, "KNX TW Dimming Triggered: " . json_encode($data[0]), 0);
                    $this->HandleTwDimming((int)$data[0], $twDirectionID, $twSwitchDirectionID);
                    $handled = true;
                    break;
                } elseif ($SenderID == $twDirectionID) {
                    $this->SendDebug(__FUNCTION__, "KNX TW Dimming Direction Changed: " . json_encode($data[0]), 0);
                    $handled = true;
                    break;
                }
            }
        }

        if (!$handled) {
            // Check Color Inputs (Absolute RGB)
            $knxColorInputs = json_decode($this->ReadPropertyString("KnxColorInputs"), true);
            foreach ($knxColorInputs as $input) {
                $colorID = isset($input['ColorID']) ? (int)$input['ColorID'] : 0;
                if ($SenderID == $colorID) {
                    $this->SendDebug(__FUNCTION__, "KNX Color Triggered: " . json_encode($data[0]), 0);
                    $this->HandleColor((int)$data[0]);
                    $handled = true;
                    break;
                }
            }
        }

        if (!$handled) {
            // Check Hue Feedback (Update KNX variables if Hue state changes)
            $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
            if ($hueInstanceID > 0) {
                // Check Status/State
                $statusID = IPS_GetObjectIDByIdent("on", $hueInstanceID);
                
                if ($SenderID == $statusID && $data[1]) {
                    $this->UpdateFeedback(0, (bool)$data[0]); // 0 = Status
                    $handled = true;
                }

                // Check Brightness/Intensity
                $brightnessID = IPS_GetObjectIDByIdent("brightness", $hueInstanceID);
                if ($SenderID == $brightnessID && $data[1]) {
                    $this->UpdateFeedback(1, (int)$data[0]); // 1 = Brightness
                    $handled = true;
                }

                // Check Color
                $colorID = IPS_GetObjectIDByIdent("color", $hueInstanceID);    
                if ($SenderID == $colorID && $data[1]) {
                    $this->UpdateFeedback(2, (int)$data[0]); // 2 = Color
                    $handled = true;
                }

                // Check Color Temperature
                $ctID = IPS_GetObjectIDByIdent("color_temperature", $hueInstanceID);
                if ($SenderID == $ctID && $data[1]) {
                    $this->UpdateFeedback(3, (int)$data[0]); // 3 = Color Temperature
                    $handled = true;
                }
            }
        }

        if (!$handled) {
            $this->SendDebug(__FUNCTION__, "Unknown SenderID: " . $SenderID, 0);
        }
    }

    /**
     * Looks up the Hue configuration (Color/Brightness) for the given KNX Scene Number and activates it.
     */
    private function CallHueScene(int $knxSceneNumber)
    {
        $mapping = json_decode($this->ReadPropertyString("SceneMapping"), true);
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");

        $this->SendDebug(__FUNCTION__, "Hue Instance: ". $hueInstanceID, 0);
        $this->SendDebug(__FUNCTION__, "Requested KNX Scene: " . $knxSceneNumber, 0);
        $this->SendDebug(__FUNCTION__, "Mapping: " . json_encode($mapping), 0);
        


        foreach ($mapping as $entry) {
            // Matching column name 'KnxNumber' from form.json
            if (isset($entry['KnxNumber']) && $entry['KnxNumber'] == $knxSceneNumber) {
                
                // Check if scene is active
                if (isset($entry['Active']) && !$entry['Active']) {
                    $this->SendDebug(__FUNCTION__, "Scene $knxSceneNumber is deactivated.", 0);
                    return;
                }

                if ($hueInstanceID > 0) {
                    
                    if (isset($entry['Brightness'])) {
                        $brightnessID = IPS_GetObjectIDByIdent("brightness", $hueInstanceID);
                        if ($brightnessID !== false) {
                            $this->SendDebug(__FUNCTION__, "Set Brightness: " . $entry['Brightness'], 0);
                            RequestAction($brightnessID, $entry['Brightness']);
                        }
                    }
                    if (isset($entry['Color'])) {
                        $colorID = IPS_GetObjectIDByIdent("color", $hueInstanceID);
                        if ($colorID !== false) {
                            $this->SendDebug(__FUNCTION__, "Set Color: " . $entry['Color'], 0);
                            RequestAction($colorID, $entry['Color']);
                        }
                    }

                }
                return;
            }
        }
        $this->SendDebug(__FUNCTION__, "No mapping found for KNX Scene " . $knxSceneNumber, 0);
    }

    /**
     * Saves the current Hue state (Color/Brightness) to the Scene Mapping list.
     */
    private function SaveHueScene(int $knxSceneNumber)
    {
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
        if ($hueInstanceID <= 0) return;

        // Try to find Color and Brightness variables
        $colorID = @IPS_GetObjectIDByIdent("color", $hueInstanceID);
        $brightnessID = @IPS_GetObjectIDByIdent("brightness", $hueInstanceID);
        $statusID = @IPS_GetObjectIDByIdent("on", $hueInstanceID);

        $color = ($colorID !== false) ? GetValue($colorID) : 0;
        $brightness = ($brightnessID !== false) ? GetValue($brightnessID) : 0;

        // Wenn die Lampe ausgeschaltet ist, speichern wir Helligkeit 0
        if ($statusID !== false && !GetValueBoolean($statusID)) {
            $brightness = 0;
        }

        $this->SendDebug("SaveHueScene", "Saving Scene $knxSceneNumber: Color=$color, Brightness=$brightness", 0);

        $mapping = json_decode($this->ReadPropertyString("SceneMapping"), true);
        
        $found = false;
        // Update existing entry
        foreach ($mapping as &$entry) {
            if (isset($entry['KnxNumber']) && $entry['KnxNumber'] == $knxSceneNumber) {
                $entry['Color'] = $color;
                $entry['Brightness'] = $brightness;
                $found = true;
                break;
            }
        }
        unset($entry); // break reference

        // Create new entry if not found and auto-create is enabled
        if (!$found) {
            if ($this->ReadPropertyBoolean("AutoCreateUnknownScenes")) {
                $mapping[] = [
                    'Active' => $this->ReadPropertyBoolean("AutoActivateNewScenes"),
                    'KnxNumber' => $knxSceneNumber,
                    'Color' => $color,
                    'Brightness' => $brightness
                ];
            } else {
                $this->SendDebug("SaveHueScene", "Ignored unknown Scene $knxSceneNumber (AutoCreate disabled)", 0);
                return;
            }
        }

        IPS_SetProperty($this->InstanceID, "SceneMapping", json_encode($mapping));
        IPS_ApplyChanges($this->InstanceID);

        // Check and update Variable Profile if needed
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);

        // Add new association to profile only if auto create unknownScenes is active
        if ($this->ReadPropertyBoolean("AutoCreateUnknownScenes")){
            
            foreach ($knxSceneInputs as $input) {
                $sceneNumberID = isset($input['SceneNumberID']) ? (int)$input['SceneNumberID'] : 0;

                if ($sceneNumberID > 0 && IPS_VariableExists($sceneNumberID)) {
                    $variable = IPS_GetVariable($sceneNumberID);
                    $profileName = $variable['VariableCustomProfile'] ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];

                    if ($profileName != "" && IPS_VariableProfileExists($profileName)) {
                        // Only modify custom profiles, not system profiles (~)
                        if (substr($profileName, 0, 1) != "~") {
                            $profile = IPS_GetVariableProfile($profileName);
                            $exists = false;
                            foreach ($profile['Associations'] as $association) {
                                if ($association['Value'] == $knxSceneNumber) {
                                    $exists = true;
                                    break;
                                }
                            }
                            if (!$exists) {
                                IPS_SetVariableProfileAssociation($profileName, $knxSceneNumber, "$knxSceneNumber - unknown", "", -1);
                                $this->SendDebug("SaveHueScene", "Added missing scene to profile: $knxSceneNumber - unknown", 0);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Imports all scenes from the variable profile of the KNX Scene Number variable into the mapping list.
     */
    public function ImportProfileScenes()
    {
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);
        $sceneNumberID = 0;

        // Find first valid input to use for import
        foreach ($knxSceneInputs as $input) {
            if (isset($input['SceneNumberID']) && $input['SceneNumberID'] > 0 && IPS_VariableExists($input['SceneNumberID'])) {
                $sceneNumberID = (int)$input['SceneNumberID'];
                break;
            }
        }

        if ($sceneNumberID <= 0) {
            echo $this->Translate("KNX Scene Variable not set or invalid.");
            return;
        }

        $variable = IPS_GetVariable($sceneNumberID);
        $profileName = $variable['VariableCustomProfile'] ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];

        if ($profileName == "" || !IPS_VariableProfileExists($profileName)) {
            echo $this->Translate("No profile assigned to KNX Scene Variable.");
            return;
        }

        $profile = IPS_GetVariableProfile($profileName);
        $mapping = json_decode($this->ReadPropertyString("SceneMapping"), true);
        
        // Extract existing numbers to avoid duplicates
        $existingNumbers = [];
        foreach ($mapping as $entry) {
            if (isset($entry['KnxNumber'])) {
                $existingNumbers[] = $entry['KnxNumber'];
            }
        }

        $addedCount = 0;
        $autoActivate = $this->ReadPropertyBoolean("AutoActivateNewScenes");

        foreach ($profile['Associations'] as $association) {
            $val = $association['Value'];
            // Check valid KNX scene range 1-64
            if ($val >= 1 && $val <= 64) {
                if (!in_array($val, $existingNumbers)) {
                    $mapping[] = [
                        'Active' => $autoActivate,
                        'KnxNumber' => $val,
                        'Color' => 0, 
                        'Brightness' => 0
                    ];
                    $existingNumbers[] = $val;
                    $addedCount++;
                }
            }
        }

        if ($addedCount > 0) {
            // Update the form field directly so the user can see and save the changes
            $this->UpdateFormField("SceneMapping", "values", json_encode($mapping));
            echo sprintf($this->Translate("Imported %d scenes from profile. Please save changes."), $addedCount);
        } else {
            echo $this->Translate("No new scenes found in profile.");
        }
    }

    /**
     * Handles KNX Switch command (On/Off) and sends it to the Hue instance.
     */
    private function HandleSwitch(bool $value)
    {
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
        if ($hueInstanceID > 0) {
            // Try to find Status or State variable
            $statusID = @IPS_GetObjectIDByIdent("on", $hueInstanceID);
            
            if ($statusID !== false) {
                RequestAction($statusID, $value);
            }
        }
    }

    /**
     * Handles KNX Color Temperature command (Absolute Kelvin) and sends it to the Hue instance.
     */
    private function HandleColorTemperature(int $kelvin)
    {
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
        if ($hueInstanceID > 0 && $kelvin > 0) {
            // Convert Kelvin to Mired (Hue uses Mired 153-500)
            // Formula: 1,000,000 / Kelvin
            $mired = intval(1000000 / $kelvin);
            
            // Clamp to valid range (Hue usually 153-500)
            if ($mired < 153) $mired = 153;
            if ($mired > 500) $mired = 500;

            $ctID = @IPS_GetObjectIDByIdent("color_temperature", $hueInstanceID);
            
            if ($ctID !== false) {
                $this->SendDebug(__FUNCTION__, "Set Color Temperature: $kelvin K -> $mired Mired", 0);
                RequestAction($ctID, $mired);
            }
        }
    }

    /**
     * Handles KNX Color command (Absolute RGB) and sends it to the Hue instance.
     */
    private function HandleColor(int $color)
    {
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
        if ($hueInstanceID > 0) {
            $colorID = IPS_GetObjectIDByIdent("color", $hueInstanceID);
            $statusID = IPS_GetObjectIDByIdent("on", $hueInstanceID);
            
            if ($colorID !== false) {
                $this->SendDebug(__FUNCTION__, "Set Color: $color", 0);

                if ($color == 0) {
                    if ($statusID !== false) {
                        RequestAction($statusID, false);
                    }
                } else {
                    RequestAction($colorID, $color);
                    if ($statusID !== false && !GetValueBoolean($statusID)) {
                        RequestAction($statusID, true);
                    }
                }
            }
        }
    }

    /**
     * Handles KNX Dimming command (Relative). Starts or stops the dimming timer.
     */
    private function HandleDimming(int $value, int $directionID, int $switchDirectionID)
    {
        if ($value == 0) {
            // Stop
            $this->SetTimerInterval('DimmingTimer', 0);
        } else {
            // Start
            $direction = false; // Default Down
            if ($directionID > 0 && IPS_VariableExists($directionID)) {
                $direction = GetValueBoolean($directionID);
            }
            
            // Update Switch Direction Variable (Feedback for 1-button dimming)
            if ($switchDirectionID > 0 && IPS_VariableExists($switchDirectionID)) {
                RequestAction($switchDirectionID, $direction);
            }

            $this->SetBuffer("DimDirection", $direction ? "Up" : "Down");
            $this->SetTimerInterval('DimmingTimer', $this->ReadPropertyInteger("DimInterval"));
        }
    }

    /**
     * Timer callback for dimming loop. Adjusts brightness in steps.
     */
    public function DimLoop()
    {
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
        if ($hueInstanceID <= 0) return;

        $brightnessID = @IPS_GetObjectIDByIdent("brightness", $hueInstanceID);

        if ($brightnessID !== false) {
            $current = GetValue($brightnessID);
            $step = $this->ReadPropertyInteger("DimStep");
            $direction = $this->GetBuffer("DimDirection");

            if ($direction == "Up") {
                $new = $current + $step;
                if ($new > 255) $new = 255;
            } else {
                $new = $current - $step;
                if ($new < 0) $new = 0;
            }

            if ($new != $current) {
                RequestAction($brightnessID, $new);
            }
        }
    }

    /**
     * Handles KNX Tunable White Dimming command (Relative).
     */
    private function HandleTwDimming(int $value, int $directionID, int $switchDirectionID)
    {
        if ($value == 0) {
            // Stop
            $this->SetTimerInterval('TwDimmingTimer', 0);
        } else {
            // Start
            $direction = false; // Default Down (Cooler/Lower Mireds)
            if ($directionID > 0 && IPS_VariableExists($directionID)) {
                $direction = GetValueBoolean($directionID);
            }
            
            // Update Switch Direction Variable (Feedback for 1-button dimming)
            if ($switchDirectionID > 0 && IPS_VariableExists($switchDirectionID)) {
                RequestAction($switchDirectionID, $direction);
            }

            // Up = Warmer (Higher Mireds), Down = Cooler (Lower Mireds)
            // Note: Hue uses Mireds (153-500). 
            $this->SetBuffer("TwDirection", $direction ? "Up" : "Down");
            $this->SetTimerInterval('TwDimmingTimer', $this->ReadPropertyInteger("TwInterval"));
        }
    }

    /**
     * Timer callback for Tunable White dimming loop. Adjusts Color Temperature in steps.
     */
    public function TwDimLoop()
    {
        $hueInstanceID = $this->ReadPropertyInteger("HueInstanceID");
        if ($hueInstanceID <= 0) return;

        $ctID = @IPS_GetObjectIDByIdent("color_temperature", $hueInstanceID);

        if ($ctID !== false) {
            $current = GetValue($ctID);
            $step = $this->ReadPropertyInteger("TwStep");
            $direction = $this->GetBuffer("TwDirection");

            // Hue Range: 153 (Cool) - 500 (Warm)
            if ($direction == "Up") {
                $new = $current + $step;
                if ($new > 500) $new = 500;
            } else {
                $new = $current - $step;
                if ($new < 153) $new = 153;
            }

            if ($new != $current) {
                RequestAction($ctID, $new);
            }
        }
    }

    /**
     * Updates KNX Feedback variables based on Hue status changes.
     * Type: 0=Status, 1=Brightness, 2=Color, 3=Color Temp
     */
    private function UpdateFeedback(int $type, $value)
    {
        $varID = 0;
        if ($type == 0) $varID = $this->ReadPropertyInteger("KnxFeedbackStatusID");
        if ($type == 1) $varID = $this->ReadPropertyInteger("KnxFeedbackBrightnessID");
        if ($type == 2) $varID = $this->ReadPropertyInteger("KnxFeedbackColorID");
        if ($type == 3) $varID = $this->ReadPropertyInteger("KnxFeedbackColorTemperatureID");

        if ($varID > 0 && IPS_VariableExists($varID)) {
            // Value conversion
            $sendValue = $value;
            if ($type == 1) { // Brightness 0-255 -> 0-100%
                $sendValue = $value;//intval(($value / 255) * 100);
            }
            if ($type == 3) { // Color Temperature 153-500 -> 
                if ($value != 0) {    
                    $sendValue = intval(1000000 / $value);
                }
            }


            $current = GetValue($varID);
            // Only update if value changed to reduce bus load
            if ($current != $sendValue) {
                RequestAction($varID, $sendValue);
            }
        }
    }
}
