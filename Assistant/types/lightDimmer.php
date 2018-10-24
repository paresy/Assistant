<?php

declare(strict_types=1);

class DeviceTypeLightDimmer
{
    private static $implementedType = 'LIGHT';

    private static $implementedTraits = [
        'BrightnessOnOff'
    ];

    private static $displayStatusPrefix = false;

    use HelperDeviceType;

    public static function getPosition()
    {
        return 1;
    }

    public static function getCaption()
    {
        return 'Light (Dimmer)';
    }

    public static function getTranslations()
    {
        return [
            'de' => [
                'Light (Dimmer)' => 'Licht (Dimmer)',
                'Variable'       => 'Variable'
            ]
        ];
    }
}

DeviceTypeRegistry::register('LightDimmer');
