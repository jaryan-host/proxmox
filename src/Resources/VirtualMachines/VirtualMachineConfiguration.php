<?php

declare(strict_types=1);

namespace Proxmox\Resources\VirtualMachines;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResourceData;

/**
 * @internal
 */
final class VirtualMachineConfiguration
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public static function toApiParameters(array $configuration): array
    {
        foreach ([
            'disk' => 'scsi0',
            'image' => 'scsi0',
            'bridge' => 'net0',
            'ip' => 'ipconfig0',
            'user' => 'ciuser',
            'password' => 'cipassword',
            'ssh_key' => 'sshkeys',
        ] as $field => $native) {
            if (array_key_exists($field, $configuration) && array_key_exists($native, $configuration)) {
                throw new InvalidArgumentException("Use either {$field} or {$native}, not both.");
            }
        }

        $cloudInit = Arr::hasAny($configuration, ['image', 'ip', 'user', 'password', 'ssh_key']);

        if ($cloudInit && array_key_exists('ide2', $configuration)) {
            throw new InvalidArgumentException('Readable Cloud-Init fields automatically configure ide2; use native fields with a custom ide2.');
        }

        if (array_key_exists('disk', $configuration)) {
            $disk = $configuration['disk'];

            if (! is_int($disk) || $disk < 1) {
                throw new InvalidArgumentException('disk must be a positive integer in GiB.');
            }

            $configuration['scsi0'] = self::identifier($configuration, 'storage').':'.$disk;
        }

        if (array_key_exists('image', $configuration)) {
            $configuration['scsi0'] = self::imageDisk($configuration['image'], $configuration['storage'] ?? null);
            $configuration += ['boot' => 'order=scsi0', 'serial0' => 'socket', 'vga' => 'serial0'];
        }

        if (Arr::hasAny($configuration, ['disk', 'image'])) {
            $configuration += ['scsihw' => 'virtio-scsi-pci'];
        }

        if (array_key_exists('bridge', $configuration)) {
            $configuration['net0'] = 'virtio,bridge='.self::identifier($configuration, 'bridge');
        }

        if (array_key_exists('ip', $configuration)) {
            $network = $configuration['net0'] ?? null;

            if (! is_string($network) || trim($network) === '') {
                throw new InvalidArgumentException('ip requires a bridge or an explicit net0 network card.');
            }

            $configuration['ipconfig0'] = self::ipConfiguration($configuration);
        } elseif (Arr::hasAny($configuration, ['prefix', 'gateway'])) {
            throw new InvalidArgumentException('prefix and gateway require an ip.');
        }

        foreach (['user' => 'ciuser', 'password' => 'cipassword', 'ssh_key' => 'sshkeys'] as $field => $native) {
            if (! array_key_exists($field, $configuration)) {
                continue;
            }

            $value = $configuration[$field];

            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("{$field} must be a non-empty string.");
            }

            $configuration[$native] = $field === 'ssh_key' ? rawurlencode($value) : $value;
            unset($configuration[$field]);
        }

        if ($cloudInit) {
            $configuration['ide2'] = self::identifier($configuration, 'storage').':cloudinit';
        }

        return Arr::except($configuration, ['disk', 'image', 'bridge', 'ip', 'prefix', 'gateway']);
    }

    public static function imageDisk(mixed $image, mixed $storage): string
    {
        $storage = Identifier::name($storage, 'storage');

        if (! is_string($image) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*:import\/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(?:qcow2|raw)$/D', $image) !== 1) {
            throw new InvalidArgumentException('image must be an importable QCOW2 or RAW volume ID such as local:import/ubuntu.qcow2 or local:import/ubuntu.img.raw.');
        }

        return $storage.':0,import-from='.$image;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, string>
     */
    public static function reinstallParameters(array $config, string $drive, string $storage, ?string $disk): array
    {
        $volumes = ResourceData::volumes($config);
        $boot = $config['boot'] ?? null;
        $bootOrder = is_string($boot) && preg_match('/(?:^|,)order=([^,]*)/', $boot, $matches) === 1
            ? explode(';', $matches[1])
            : [];

        $disk ??= match (true) {
            $bootOrder !== [] => array_values(array_intersect($bootOrder, array_keys($volumes)))[0] ?? null,
            is_string($config['bootdisk'] ?? null) => $config['bootdisk'],
            count($volumes) === 1 => array_key_first($volumes),
            default => null,
        };

        if ($disk === null) {
            throw new InvalidArgumentException('Could not determine the boot disk. Pass the OS disk key as disk.');
        }

        $existing = $config[$disk] ?? null;
        $oldStorage = $volumes[$disk]['storage'] ?? null;

        if ($oldStorage === null || ! is_string($existing)) {
            throw new InvalidArgumentException("The OS disk {$disk} must be an attached, storage-backed hard disk.");
        }

        $options = [$drive];

        foreach (explode(',', $existing) as $option) {
            if (preg_match('/^([a-z][a-z0-9_-]*)=/', $option, $matches) !== 1
                || in_array($matches[1], ['file', 'volume', 'size', 'format', 'import-from'], true)
                || ($matches[1] === 'shared' && $oldStorage !== $storage)) {
                continue;
            }

            $options[] = $option;
        }

        return [
            $disk => implode(',', $options),
            'boot' => 'order='.implode(';', array_unique([$disk, ...array_filter($bootOrder)])),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function identifier(array $configuration, string $field): string
    {
        return Identifier::name($configuration[$field] ?? null, $field);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function ipConfiguration(array $configuration): string
    {
        $ip = $configuration['ip'];

        if ($ip === 'dhcp') {
            foreach (['prefix', 'gateway'] as $field) {
                if (array_key_exists($field, $configuration)) {
                    throw new InvalidArgumentException("{$field} cannot be used with DHCP.");
                }
            }

            return 'ip=dhcp';
        }

        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('ip must be an IPv4 address without a prefix, or "dhcp".');
        }

        $prefix = $configuration['prefix'] ?? null;

        if (! is_int($prefix) || $prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('A static ip requires an integer prefix between 0 and 32.');
        }

        $value = 'ip='.$ip.'/'.$prefix;

        if (array_key_exists('gateway', $configuration)) {
            $gateway = $configuration['gateway'];

            if (! is_string($gateway) || filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('gateway must be an IPv4 address.');
            }

            $value .= ',gw='.$gateway;
        }

        return $value;
    }
}
