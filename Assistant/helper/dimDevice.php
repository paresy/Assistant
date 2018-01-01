<?php

declare(strict_types=1);

trait HelperDimDevice
{
    private static function getDimCompatibility($variableID)
    {
        $targetVariable = IPS_GetVariable($variableID);

        if ($targetVariable['VariableType'] != 1 /* Integer */ && $targetVariable['VariableType'] != 2 /* Float */) {
            return 'Int/Float required';
        }

        if ($targetVariable['VariableCustomProfile'] != '') {
            $profileName = $targetVariable['VariableCustomProfile'];
        } else {
            $profileName = $targetVariable['VariableProfile'];
        }

        if (!IPS_VariableProfileExists($profileName)) {
            return 'Profile required';
        }

        if ($targetVariable['VariableCustomAction'] != '') {
            $profileAction = $targetVariable['VariableCustomAction'];
        } else {
            $profileAction = $targetVariable['VariableAction'];
        }

        if (!($profileAction > 10000)) {
            return 'Action required';
        }

        return 'OK';
    }

    private static function getDimValue($variableID)
    {
        $targetVariable = IPS_GetVariable($variableID);

        if ($targetVariable['VariableCustomProfile'] != '') {
            $profileName = $targetVariable['VariableCustomProfile'];
        } else {
            $profileName = $targetVariable['VariableProfile'];
        }

        $profile = IPS_GetVariableProfile($profileName);

        $valueToPercent = function ($value) use ($profile) {
            return (($value - $profile['MinValue']) / ($profile['MaxValue'] - $profile['MinValue'])) * 100;
        };

        return $valueToPercent(GetValue($variableID));
    }

    private static function dimDevice($variableID, $value)
    {
        if (!IPS_VariableExists($variableID)) {
            return false;
        }

        $targetVariable = IPS_GetVariable($variableID);

        if ($targetVariable['VariableCustomProfile'] != '') {
            $profileName = $targetVariable['VariableCustomProfile'];
        } else {
            $profileName = $targetVariable['VariableProfile'];
        }

        if (!IPS_VariableProfileExists($profileName)) {
            return false;
        }

        $profile = IPS_GetVariableProfile($profileName);

        if ($targetVariable['VariableCustomAction'] != 0) {
            $profileAction = $targetVariable['VariableCustomAction'];
        } else {
            $profileAction = $targetVariable['VariableAction'];
        }

        if ($profileAction < 10000) {
            return false;
        }

        $percentToValue = function ($value) use ($profile) {
            return ($value / 100) * ($profile['MaxValue'] - $profile['MinValue']) + $profile['MinValue'];
        };

        if ($targetVariable['VariableType'] == 1 /* Integer */) {
            $value = intval($percentToValue($value));
        } elseif ($targetVariable['VariableType'] == 2 /* Float */) {
            $value = floatval($percentToValue($value));
        } else {
            return false;
        }

        if (IPS_InstanceExists($profileAction)) {
            IPS_RunScriptText('IPS_RequestAction(' . var_export($profileAction, true) . ', ' . var_export(IPS_GetObject($variableID)['ObjectIdent'], true) . ', ' . var_export($value, true) . ');');
        } elseif (IPS_ScriptExists($profileAction)) {
            IPS_RunScriptEx($profileAction, ['VARIABLE' => $variableID, 'VALUE' => $value, 'SENDER' => 'WebFront']);
        } else {
            return false;
        }

        return true;
    }
}
