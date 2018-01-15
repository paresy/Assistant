<?php

declare(strict_types=1);

class DeviceTraitBrightness
{
    const propertyPrefix = 'Brightness';

    use HelperDimDevice;

    public static function getColumns()
    {
        return [
            [
                'label' => 'VariableID',
                'name'  => self::propertyPrefix . 'ID',
                'width' => '100px',
                'add'   => 0,
                'edit'  => [
                    'type' => 'SelectVariable'
                ]
            ]
        ];
    }

    public static function getStatus($configuration)
    {
        return self::getDimCompatibility($configuration[self::propertyPrefix . 'ID']);
    }

    public static function doQuery($configuration)
    {
        if (IPS_VariableExists($configuration[self::propertyPrefix . 'ID'])) {
            return [
                'brightness' => self::getDimValue($configuration[self::propertyPrefix . 'ID'])
            ];
        } else {
            return [];
        }
    }

    public static function doExecute($configuration, $command, $data)
    {
        switch ($command) {
            case 'action.devices.commands.BrightnessAbsolute':
                if (self::dimDevice($configuration[self::propertyPrefix . 'ID'], $data['brightness'])) {
                    return [
                        'ids'    => [$configuration['ID']],
                        'status' => 'SUCCESS',
                        'states' => [
                            'brightness' => self::getDimValue($configuration[self::propertyPrefix . 'ID']),
                            'online'     => true
                        ]
                    ];
                } else {
                    return [
                        'ids'       => [$configuration['ID']],
                        'status'    => 'ERROR',
                        'errorCode' => 'deviceTurnedOff'
                    ];
                }
                break;
            default:
                throw new Exception('Command is not supported by this trait!');
        }
    }

    public static function supportedTraits()
    {
        return [
            'action.devices.traits.Brightness'
        ];
    }

    public static function supportedCommands()
    {
        return [
            'action.devices.commands.BrightnessAbsolute'
        ];
    }

    public static function getAttributes()
    {
        return [];
    }
}
