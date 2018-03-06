<?php

declare(strict_types=1);

class DeviceTraitColorSpectrumBrightnessOnOff
{
    const propertyPrefix = 'ColorSpectrumBrightnessOnOff';

    use HelperColorDevice;

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
        return self::getColorCompatibility($configuration[self::propertyPrefix . 'ID']);
    }

    public static function doQuery($configuration)
    {
        if (IPS_VariableExists($configuration[self::propertyPrefix . 'ID'])) {
            if ((time() - IPS_GetVariable($configuration[self::propertyPrefix . 'ID'])['VariableUpdated']) > 30 * 60) {
                return [
                    'ids'       => [$configuration['ID']],
                    'status'    => 'ERROR',
                    'errorCode' => 'deviceTurnedOff'
                ];
            } else {
                return [
                    'color' => [
                        'spectrumRGB' => self::getColorValue($configuration[self::propertyPrefix . 'ID'])
                    ],
                    'brightness' => intval(self::getColorBrightness($configuration[self::propertyPrefix . 'ID'])),
                    'on'         => self::getColorValue($configuration[self::propertyPrefix . 'ID']) != 0
                ];
            }
        } else {
            return [];
        }
    }

    public static function doExecute($configuration, $command, $data)
    {
        switch ($command) {
            case 'action.devices.commands.ColorAbsolute':
                if (self::colorDevice($configuration[self::propertyPrefix . 'ID'], $data['color']['spectrumRGB'])) {
                    $i = 0;
                    while (($data['color']['spectrumRGB'] != self::getColorValue($configuration[self::propertyPrefix . 'ID'])) && $i < 10) {
                        $i++;
                        usleep(100000);
                    }
                    return [
                        'ids'    => [$configuration['ID']],
                        'status' => 'SUCCESS',
                        'states' => [
                            'color'  => [
                                'spectrumRGB' => self::getColorValue($configuration[self::propertyPrefix . 'ID'])
                            ],
                            'online' => true
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

            case 'action.devices.commands.OnOff':
                $newValue = $data['on'] ? 0xFFFFFF : 0;
                if (self::colorDevice($configuration[self::propertyPrefix . 'ID'], $newValue)) {
                    $i = 0;
                    while (($newValue != self::getColorValue($configuration[self::propertyPrefix . 'ID'])) && $i < 10) {
                        $i++;
                        usleep(100000);
                    }
                    return [
                        'ids'    => [$configuration['ID']],
                        'status' => 'SUCCESS',
                        'states' => [
                            'on'     => self::getColorValue($configuration[self::propertyPrefix . 'ID']) > 0,
                            'online' => true
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

            case 'action.devices.commands.BrightnessAbsolute':
                if (self::setColorBrightness($configuration[self::propertyPrefix . 'ID'], $data['brightness'])) {
                    $i = 0;
                    while (($data['brightness'] != self::getColorBrightness($configuration[self::propertyPrefix . 'ID'])) && $i < 10) {
                        $i++;
                        usleep(100000);
                    }
                    return [
                        'ids'    => [$configuration['ID']],
                        'status' => 'SUCCESS',
                        'states' => [
                            'brightness' => intval(self::getColorBrightness($configuration[self::propertyPrefix . 'ID'])),
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
            'action.devices.traits.ColorSpectrum',
            'action.devices.traits.Brightness',
            'action.devices.traits.OnOff'
        ];
    }

    public static function supportedCommands()
    {
        return [
            'action.devices.commands.ColorAbsolute',
            'action.devices.commands.BrightnessAbsolute',
            'action.devices.commands.OnOff'
        ];
    }

    public static function getAttributes()
    {
        return [
            'colorModel' => 'rgb'
        ];
    }
}
