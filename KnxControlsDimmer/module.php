<?php

declare(strict_types=1);

// CLASS KnxControlsDimmer
class KnxControlsDimmer extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Target device variables
        $this->RegisterPropertyInteger("StatusVariableID", 0);
        $this->RegisterPropertyInteger("BrightnessVariableID", 0);
        $this->RegisterPropertyInteger("MaxBrightnessValue", 100); // e.g., 100 for % or 255 for 8-bit

        // KNX Inputs
        $this->RegisterPropertyString("KnxSceneInputs", "[]");
        $this->RegisterPropertyString("KnxSwitchInputs", "[]");
        $this->RegisterPropertyString("KnxDimInputs", "[]");
        $this->RegisterPropertyInteger("DimStep", 5); // 5% step
        $this->RegisterPropertyInteger("DimInterval", 200);

        // Scene Management
        $this->RegisterPropertyString("SceneMapping", "[]");
        $this->RegisterPropertyBoolean("AutoCreateUnknownScenes", true);
        $this->RegisterPropertyBoolean("AutoActivateNewScenes", false);

        // KNX Feedback
        $this->RegisterPropertyInteger("KnxFeedbackStatusID", 0);
        $this->RegisterPropertyInteger("KnxFeedbackBrightnessID", 0);

        // Timers
        $this->RegisterTimer('DimmingTimer', 0, 'KCD_DimLoop(' . $this->InstanceID . ');');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Scene profile logic from Hue module can be reused
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);
        $options = [];
        foreach ($knxSceneInputs as $input) {
            $knxSceneID = isset($input['SceneNumberID']) ? (int)$input['SceneNumberID'] : 0;
            if ($knxSceneID > 0 && IPS_VariableExists($knxSceneID)) {
                $variable = IPS_GetVariable($knxSceneID);
                $profileName = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];
                if ($profileName != "" && IPS_VariableProfileExists($profileName)) {
                    $profile = IPS_GetVariableProfile($profileName);
                    foreach ($profile['Associations'] as $association) {
                        if ($association['Value'] >= 1 && $association['Value'] <= 64) {
                            $options[] = ['caption' => $association['Value'] . " - " . $association['Name'], 'value' => $association['Value']];
                        }
                    }
                    if (count($options) > 0) break;
                }
            }
        }

        foreach ($form['elements'] as &$element) {
            if ($element['type'] == 'ExpansionPanel') {
                foreach ($element['items'] as &$item) {
                    if (count($options) > 0 && isset($item['name']) && $item['name'] == 'SceneMapping') {
                        foreach ($item['columns'] as &$column) {
                            if ($column['name'] == 'KnxNumber') {
                                $column['edit'] = ['type' => 'Select', 'options' => $options];
                                $column['width'] = '40%';
                            }
                        }
                    }
                }
            }
        }
        unset($element, $item, $column);

        return json_encode($form);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Unregister all messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Register KNX Scene inputs
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);
        foreach ($knxSceneInputs as $input) {
            if (!empty($input['SceneNumberID'])) $this->RegisterMessage($input['SceneNumberID'], VM_UPDATE);
            if (!empty($input['ControlID'])) $this->RegisterMessage($input['ControlID'], VM_UPDATE);
        }

        // Register KNX Switch inputs
        $knxSwitchInputs = json_decode($this->ReadPropertyString("KnxSwitchInputs"), true);
        foreach ($knxSwitchInputs as $input) {
            if (!empty($input['SwitchID'])) $this->RegisterMessage($input['SwitchID'], VM_UPDATE);
        }

        // Register KNX Dim inputs
        $knxDimInputs = json_decode($this->ReadPropertyString("KnxDimInputs"), true);
        foreach ($knxDimInputs as $input) {
            if (!empty($input['DimStepID'])) $this->RegisterMessage($input['DimStepID'], VM_UPDATE);
            if (!empty($input['DimDirectionID'])) $this->RegisterMessage($input['DimDirectionID'], VM_UPDATE);
        }

        // Register Target Device variables for feedback
        $statusVarID = $this->ReadPropertyInteger("StatusVariableID");
        if ($statusVarID > 0) $this->RegisterMessage($statusVarID, VM_UPDATE);

        $brightnessVarID = $this->ReadPropertyInteger("BrightnessVariableID");
        if ($brightnessVarID > 0) $this->RegisterMessage($brightnessVarID, VM_UPDATE);
    }

    public function MessageSink($timestamp, $SenderID, $message, $data)
    {
        $this->SendDebug(__FUNCTION__, "SenderID: $SenderID, Value: " . json_encode($data[0]), 0);
        if ($message !== VM_UPDATE || !$data[1]) return; // Only on value change

        $handled = false;

        // --- Handle KNX Inputs ---

        // Scene Inputs
        $knxSceneInputs = json_decode($this->ReadPropertyString("KnxSceneInputs"), true);
        foreach ($knxSceneInputs as $input) {
            $sceneNumberID = $input['SceneNumberID'] ?? 0;
            $sceneControlID = $input['ControlID'] ?? 0;

            if ($SenderID == $sceneNumberID) {
                $isSaveMode = ($sceneControlID > 0 && GetValueBoolean($sceneControlID));
                if ($isSaveMode) $this->SaveScene((int)$data[0]);
                else $this->CallScene((int)$data[0]);
                $handled = true;
                break;
            }
            if ($SenderID == $sceneControlID && $data[0] == true) {
                if ($sceneNumberID > 0) $this->SaveScene(GetValueInteger($sceneNumberID));
                $handled = true;
                break;
            }
        }
        if ($handled) return;

        // Switch Inputs
        $knxSwitchInputs = json_decode($this->ReadPropertyString("KnxSwitchInputs"), true);
        foreach ($knxSwitchInputs as $input) {
            if ($SenderID == ($input['SwitchID'] ?? 0)) {
                $this->HandleSwitch((bool)$data[0]);
                return;
            }
        }

        // Dim Inputs
        $knxDimInputs = json_decode($this->ReadPropertyString("KnxDimInputs"), true);
        foreach ($knxDimInputs as $input) {
            if ($SenderID == ($input['DimStepID'] ?? 0)) {
                $this->HandleDimming((int)$data[0], $input['DimDirectionID'] ?? 0, $input['DimSwitchDirectionID'] ?? 0);
                return;
            }
        }

        // --- Handle Feedback from Target Device ---
        $statusVarID = $this->ReadPropertyInteger("StatusVariableID");
        $brightnessVarID = $this->ReadPropertyInteger("BrightnessVariableID");

        if ($SenderID == $statusVarID) {
            $this->UpdateFeedback(0, (bool)$data[0]); // Type 0 = Status
        } elseif ($SenderID == $brightnessVarID) {
            $this->UpdateFeedback(1, (int)$data[0]); // Type 1 = Brightness
        }
    }

    private function CallScene(int $knxSceneNumber)
    {
        $mapping = json_decode($this->ReadPropertyString("SceneMapping"), true);
        $statusVarID = $this->ReadPropertyInteger("StatusVariableID");
        $brightnessVarID = $this->ReadPropertyInteger("BrightnessVariableID");

        foreach ($mapping as $entry) {
            if (($entry['KnxNumber'] ?? 0) == $knxSceneNumber) {
                if (!($entry['Active'] ?? false)) {
                    $this->SendDebug(__FUNCTION__, "Scene $knxSceneNumber is deactivated.", 0);
                    return;
                }

                $brightness = $entry['Brightness'] ?? null;

                if ($brightness !== null) {
                    if ($brightnessVarID > 0) {
                        $this->SendDebug(__FUNCTION__, "Set Brightness: $brightness", 0);
                        RequestAction($brightnessVarID, $brightness);
                    }
                    // Also update status based on brightness
                    if ($statusVarID > 0) {
                        RequestAction($statusVarID, $brightness > 0);
                    }
                }
                return;
            }
        }
        $this->SendDebug(__FUNCTION__, "No mapping found for KNX Scene " . $knxSceneNumber, 0);
    }

    private function SaveScene(int $knxSceneNumber)
    {
        $statusVarID = $this->ReadPropertyInteger("StatusVariableID");
        $brightnessVarID = $this->ReadPropertyInteger("BrightnessVariableID");

        if ($statusVarID <= 0 || $brightnessVarID <= 0) return;

        $is_on = GetValueBoolean($statusVarID);
        $brightness = $is_on ? GetValueInteger($brightnessVarID) : 0;

        $this->SendDebug("SaveScene", "Saving Scene $knxSceneNumber: Brightness=$brightness", 0);

        $mapping = json_decode($this->ReadPropertyString("SceneMapping"), true);
        $found = false;
        foreach ($mapping as &$entry) {
            if (($entry['KnxNumber'] ?? 0) == $knxSceneNumber) {
                $entry['Brightness'] = $brightness;
                $found = true;
                break;
            }
        }
        unset($entry);

        if (!$found && $this->ReadPropertyBoolean("AutoCreateUnknownScenes")) {
            $mapping[] = [
                'Active' => $this->ReadPropertyBoolean("AutoActivateNewScenes"),
                'KnxNumber' => $knxSceneNumber,
                'Brightness' => $brightness
            ];
        }

        IPS_SetProperty($this->InstanceID, "SceneMapping", json_encode($mapping));
        IPS_ApplyChanges($this->InstanceID);
    }

    private function HandleSwitch(bool $value)
    {
        $statusVarID = $this->ReadPropertyInteger("StatusVariableID");
        if ($statusVarID > 0) {
            RequestAction($statusVarID, $value);
        }
    }

    private function HandleDimming(int $value, int $directionID, int $switchDirectionID)
    {
        if ($value == 0) { // Stop
            $this->SetTimerInterval('DimmingTimer', 0);
        } else { // Start
            $direction = ($directionID > 0 && IPS_VariableExists($directionID)) ? GetValueBoolean($directionID) : false; // Default Down
            if ($switchDirectionID > 0 && IPS_VariableExists($switchDirectionID)) RequestAction($switchDirectionID, $direction);
            $this->SetBuffer("DimDirection", $direction ? "Up" : "Down");
            $this->SetTimerInterval('DimmingTimer', $this->ReadPropertyInteger("DimInterval"));
        }
    }

    public function DimLoop()
    {
        $brightnessVarID = $this->ReadPropertyInteger("BrightnessVariableID");
        if ($brightnessVarID <= 0) return;

        $current = GetValueInteger($brightnessVarID);
        $step = $this->ReadPropertyInteger("DimStep");
        $max = $this->ReadPropertyInteger("MaxBrightnessValue");
        $direction = $this->GetBuffer("DimDirection");

        if ($direction == "Up") {
            $new = min($current + $step, $max);
        } else {
            $new = max($current - $step, 0);
        }

        if ($new != $current) {
            RequestAction($brightnessVarID, $new);
        }
    }

    private function UpdateFeedback(int $type, $value)
    {
        $varID = 0;
        if ($type == 0) $varID = $this->ReadPropertyInteger("KnxFeedbackStatusID");
        if ($type == 1) $varID = $this->ReadPropertyInteger("KnxFeedbackBrightnessID");

        if ($varID > 0 && IPS_VariableExists($varID)) {
            $sendValue = $value;
            if ($type == 1) { // Brightness
                $max = $this->ReadPropertyInteger("MaxBrightnessValue");
                if ($max > 100) { // e.g. 255 -> 0..100
                    $sendValue = intval(($value / $max) * 100);
                }
            }

            if (GetValue($varID) != $sendValue) {
                RequestAction($varID, $sendValue);
            }
        }
    }
}