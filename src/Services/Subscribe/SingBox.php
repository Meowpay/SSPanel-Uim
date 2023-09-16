<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use App\Utils\Tools;
use function array_filter;
use function array_key_exists;
use function array_merge;
use function json_decode;
use function json_encode;

final class SingBox extends Base
{
    public function getContent($user): string
    {
        $nodes = [];
        $singbox_config = $_ENV['SingBox_Config'];
        $nodes_raw = Tools::getSubNodes($user);

        foreach ($nodes_raw as $node_raw) {
            $node_custom_config = json_decode($node_raw->custom_config, true);
            //檢查是否配置“前端/订阅中下发的服务器地址”
            if (! array_key_exists('server_user', $node_custom_config)) {
                $server = $node_raw->server;
            } else {
                $server = $node_custom_config['server_user'];
            }

            switch ((int) $node_raw->sort) {
                case 0:
                    $node = [
                        'type' => 'shadowsocks',
                        'tag' => $node_raw->name,
                        'server' => $server,
                        'server_port' => (int) $user->port,
                        'method' => $user->method,
                        'password' => $user->passwd,
                    ];

                    break;
                case 1:
                    $ss_2022_port = $node_custom_config['ss_2022_port'] ?? ($node_custom_config['offset_port_user']
                        ?? ($node_custom_config['offset_port_node'] ?? 443));
                    $method = $node_custom_config['method'] ?? '2022-blake3-aes-128-gcm';

                    $pk_len = match ($method) {
                        '2022-blake3-aes-128-gcm' => 16,
                        default => 32,
                    };

                    $user_pk = Tools::getSs2022UserPk($user, $pk_len);
                    $server_key = $node_custom_config['server_key'] ?? '';

                    $node = [
                        'type' => 'shadowsocks',
                        'tag' => $node_raw->name,
                        'server' => $server,
                        'server_port' => (int) $ss_2022_port,
                        'method' => $method,
                        'password' => $server_key === '' ? $user_pk : $server_key . ':' .$user_pk,
                    ];

                    break;
                case 2:
                    $tuic_port = $node_custom_config['tuic_port'] ?? ($node_custom_config['offset_port_user']
                        ?? ($node_custom_config['offset_port_node'] ?? 443));
                    $host = $node_custom_config['host'] ?? '';
                    $allow_insecure = $node_custom_config['allow_insecure'] ?? false;
                    $congestion_control = $node_custom_config['congestion_control'] ?? 'bbr';

                    $node = [
                        'type' => 'tuic',
                        'tag' => $node_raw->name,
                        'server' => $server,
                        'server_port' => (int) $tuic_port,
                        'uuid' => $user->uuid,
                        'password' => $user->passwd,
                        'congestion_control' => $congestion_control,
                        'zero_rtt_handshake' => true,
                        'tls' => [
                            'enabled' => true,
                            'server_name' => $host,
                            'insecure' => (bool) $allow_insecure,
                        ],
                    ];

                    $node['tls'] = array_filter($node['tls']);

                    break;
                case 11:
                    $v2_port = $node_custom_config['v2_port'] ?? ($node_custom_config['offset_port_user']
                        ?? ($node_custom_config['offset_port_node'] ?? 443));
                    $alter_id = $node_custom_config['alter_id'] ?? '0';
                    $security = $node_custom_config['security'] ?? 'auto';
                    $transport = $node_custom_config['network'] ?? '';
                    $host = [];
                    $host[] = $node_custom_config['header']['request']['headers']['Host'][0] ??
                        $node_custom_config['host'] ?? '';
                    $path = $node_custom_config['header']['request']['path'][0] ?? $node_custom_config['path'] ?? '';
                    $headers = $node_custom_config['header']['request']['headers'] ?? [];
                    $service_name = $node_custom_config['servicename'] ?? '';

                    $node = [
                        'type' => 'vmess',
                        'tag' => $node_raw->name,
                        'server' => $server,
                        'server_port' => (int) $v2_port,
                        'uuid' => $user->uuid,
                        'security' => $security,
                        'alter_id' => (int) $alter_id,
                        'transport' => [
                            'type' => $transport,
                            'host' => $host,
                            'path' => $path,
                            'headers' => $headers,
                            'service_name' => $service_name,
                        ],
                    ];

                    $node['transport'] = array_filter($node['transport']);

                    break;
                case 14:
                    $trojan_port = $node_custom_config['trojan_port'] ?? ($node_custom_config['offset_port_user']
                        ?? ($node_custom_config['offset_port_node'] ?? 443));
                    $host = $node_custom_config['host'] ?? '';
                    $allow_insecure = $node_custom_config['allow_insecure'] ?? false;
                    $transport = $node_custom_config['network']
                        ?? (array_key_exists('grpc', $node_custom_config)
                        && $node_custom_config['grpc'] === '1' ? 'grpc' : '');
                    $path = $node_custom_config['header']['request']['path'][0] ?? $node_custom_config['path'] ?? '';
                    $headers = $node_custom_config['header']['request']['headers'] ?? [];
                    $service_name = $node_custom_config['servicename'] ?? '';

                    $node = [
                        'type' => 'trojan',
                        'tag' => $node_raw->name,
                        'server' => $server,
                        'server_port' => (int) $trojan_port,
                        'password' => $user->uuid,
                        'tls' => [
                            'enabled' => true,
                            'server_name' => $host,
                            'insecure' => (bool) $allow_insecure,
                        ],
                        'transport' => [
                            'type' => $transport,
                            'path' => $path,
                            'headers' => $headers,
                            'service_name' => $service_name,
                        ],
                    ];

                    $node['tls'] = array_filter($node['tls']);
                    $node['transport'] = array_filter($node['transport']);

                    break;
                default:
                    $node = [];
                    break;
            }

            if ($node === []) {
                continue;
            }

            $nodes[] = $node;
            $singbox_config['outbounds'][0]['outbounds'][] = $node_raw->name;
        }

        $singbox_config['outbounds'] = array_merge($singbox_config['outbounds'], $nodes);
        $singbox_config['experimental']['clash_api']['cache_id'] = $_ENV['appName'];

        return json_encode($singbox_config);
    }
}
