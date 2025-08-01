<?php

/*
 * LibreNMS
 *
 * Copyright (c) 2014 Neil Lathwood <https://github.com/laf/ http://www.lathwood.co.uk/fa>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
 */

use App\Actions\Device\ValidateDeviceAndCreate;
use App\Facades\LibrenmsConfig;
use App\Models\Availability;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceOutage;
use App\Models\Eventlog;
use App\Models\Ipv4Address;
use App\Models\Ipv4Mac;
use App\Models\Ipv4Network;
use App\Models\Ipv6Address;
use App\Models\Ipv6Network;
use App\Models\Location;
use App\Models\MplsSap;
use App\Models\MplsService;
use App\Models\OspfPort;
use App\Models\Ospfv3Nbr;
use App\Models\Ospfv3Port;
use App\Models\PollerGroup;
use App\Models\Port;
use App\Models\PortGroup;
use App\Models\PortsFdb;
use App\Models\PortsNac;
use App\Models\Sensor;
use App\Models\ServiceTemplate;
use App\Models\UserPref;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LibreNMS\Alerting\QueryBuilderParser;
use LibreNMS\Billing;
use LibreNMS\Enum\MaintenanceBehavior;
use LibreNMS\Enum\Severity;
use LibreNMS\Exceptions\InvalidIpException;
use LibreNMS\Exceptions\InvalidTableColumnException;
use LibreNMS\Util\Graph;
use LibreNMS\Util\IP;
use LibreNMS\Util\IPv4;
use LibreNMS\Util\Mac;
use LibreNMS\Util\Number;

function api_success($result, $result_name, $message = null, $code = 200, $count = null, $extra = null): JsonResponse
{
    if (isset($result) && ! isset($result_name)) {
        return api_error(500, 'Result name not specified');
    }

    $output = ['status' => 'ok'];

    if (isset($result)) {
        $output[$result_name] = $result;
    }
    if (isset($message) && $message != '') {
        $output['message'] = $message;
    }
    if (! isset($count) && is_array($result)) {
        $count = count($result);
    }
    if (isset($count)) {
        $output['count'] = $count;
    }
    if (isset($extra)) {
        $output = array_merge($output, $extra);
    }

    return response()->json($output, $code, [], JSON_PRETTY_PRINT);
} // end api_success()

function api_success_noresult($code, $message = null): JsonResponse
{
    return api_success(null, null, $message, $code);
} // end api_success_noresult

function api_error($statusCode, $message): JsonResponse
{
    return response()->json([
        'status' => 'error',
        'message' => $message,
    ], $statusCode, [], JSON_PRETTY_PRINT);
} // end api_error()

function api_not_found(): JsonResponse
{
    return api_error(404, "This API route doesn't exist.");
}

function api_get_graph(Request $request, array $additional = [])
{
    try {
        $vars = $request->only([
            'from',
            'to',
            'legend',
            'title',
            'absolute',
            'font',
            'bg',
            'bbg',
            'title',
            'graph_title',
            'nototal',
            'nodetails',
            'noagg',
            'inverse',
            'previous',
            'duration',
        ]);

        $graph = Graph::get([
            'width' => $request->get('width', 1075),
            'height' => $request->get('height', 300),
            ...$additional,
            ...$vars,
        ]);

        if ($request->get('output') === 'base64') {
            return api_success(['image' => $graph->base64(), 'content-type' => $graph->contentType()], 'image');
        }

        return response($graph->data, 200, ['Content-Type' => $graph->contentType()]);
    } catch (\LibreNMS\Exceptions\RrdGraphException $e) {
        return api_error(500, $e->getMessage());
    }
}

function check_bill_permission($bill_id, $callback)
{
    if (! bill_permitted($bill_id)) {
        return api_error(403, 'Insufficient permissions to access this bill');
    }

    return $callback($bill_id);
}

function check_device_permission($device_id, $callback = null)
{
    if (! device_permitted($device_id)) {
        return api_error(403, 'Insufficient permissions to access this device');
    }

    return is_callable($callback) ? $callback($device_id) : true;
}

function check_port_permission($port_id, $device_id, $callback)
{
    if (! device_permitted($device_id) && ! port_permitted($port_id, $device_id)) {
        return api_error(403, 'Insufficient permissions to access this port');
    }

    return $callback($port_id);
}

function get_graph_by_port_hostname(Request $request, $ifname = null, $type = 'port_bits')
{
    // This will return a graph for a given port by the ifName
    $hostname = $request->route('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $vars = [
        'port' => $ifname ?: $request->route('ifname'),
        'type' => $request->route('type', $type),
    ];

    $port_field = $request->get('ifDescr') ? 'ifDescr' : 'ifName'; // don't accept user input
    $vars['id'] = Port::where([
        'device_id' => $device_id,
        'deleted' => 0,
        $port_field => $vars['port'],
    ])->value('port_id');

    return check_port_permission($vars['id'], $device_id, function () use ($request, $vars) {
        return api_get_graph($request, $vars);
    });
}

function get_port_stats_by_port_hostname(Illuminate\Http\Request $request)
{
    $ifName = $request->route('ifname');

    // handle %2f in paths and pass to get_graph_by_port_hostname if needed
    if (Str::contains($ifName, '/')) {
        $parts = explode('/', $request->path());

        if (isset($parts[5])) {
            $ifName = urldecode($parts[5]);
            if (isset($parts[6])) {
                return get_graph_by_port_hostname($request, $ifName, $parts[6]);
            }
        }
    }

    // This will return port stats based on a devices hostname and ifName
    $hostname = $request->route('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $port = dbFetchRow('SELECT * FROM `ports` WHERE `device_id`=? AND `ifName`=? AND `deleted` = 0', [$device_id, $ifName]);

    return check_port_permission($port['port_id'], $device_id, function () use ($request, $port) {
        $in_rate = $port['ifInOctets_rate'] * 8;
        $out_rate = $port['ifOutOctets_rate'] * 8;
        $port['in_rate'] = Number::formatSi($in_rate, 2, 0, 'bps');
        $port['out_rate'] = Number::formatSi($out_rate, 2, 0, 'bps');
        $port['in_perc'] = Number::calculatePercent($in_rate, $port['ifSpeed']);
        $port['out_perc'] = Number::calculatePercent($out_rate, $port['ifSpeed']);
        $port['in_pps'] = Number::formatBi($port['ifInUcastPkts_rate'], 2, 0, '');
        $port['out_pps'] = Number::formatBi($port['ifOutUcastPkts_rate'], 2, 0, '');

        //only return requested columns
        if ($request->has('columns')) {
            $cols = explode(',', $request->get('columns'));
            foreach (array_keys($port) as $c) {
                if (! in_array($c, $cols)) {
                    unset($port[$c]);
                }
            }
        }

        return api_success($port, 'port');
    });
}

function get_graph_generic_by_hostname(Request $request)
{
    // This will return a graph type given a device id.
    $hostname = $request->route('hostname');
    $sensor_id = $request->route('sensor_id');
    $vars = [];
    $vars['type'] = $request->route('type', 'device_uptime');
    if (isset($sensor_id)) {
        $vars['id'] = $sensor_id;
        if (Str::contains($vars['type'], '_wireless')) {
            $vars['type'] = str_replace('device_', '', $vars['type']);
        } else {
            // If this isn't a wireless graph we need to fix the name.
            $vars['type'] = str_replace('device_', 'sensor_', $vars['type']);
        }
    }

    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $device = device_by_id_cache($device_id);
    $vars['device'] = $device['device_id'];

    return check_device_permission($device_id, function () use ($request, $vars) {
        return api_get_graph($request, $vars);
    });
}

function get_graph_by_service(Request $request)
{
    $vars = [];
    $vars['id'] = $request->route('id');
    $vars['type'] = 'service_graph';
    $vars['ds'] = $request->route('datasource');

    $hostname = $request->route('hostname');
    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $device = device_by_id_cache($device_id);
    $vars['device'] = $device['device_id'];

    return check_device_permission($device_id, function () use ($request, $vars) {
        return api_get_graph($request, $vars);
    });
}

function list_locations()
{
    $locations = dbFetchRows('SELECT `locations`.* FROM `locations` WHERE `locations`.`location` IS NOT NULL');
    $total_locations = count($locations);
    if ($total_locations == 0) {
        return api_error(404, 'Locations do not exist');
    }

    return api_success($locations, 'locations');
}

function get_device(Illuminate\Http\Request $request)
{
    // return details of a single device
    $hostname = $request->route('hostname');

    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    // find device matching the id
    $device = device_by_id_cache($device_id);
    if (! $device || ! isset($device['device_id'])) {
        return api_error(404, "Device $hostname does not exist");
    }

    return check_device_permission($device_id, function () use ($device) {
        $host_id = get_vm_parent_id($device);
        if (is_numeric($host_id)) {
            $device = array_merge($device, ['parent_id' => $host_id]);
        }

        return api_success([$device], 'devices');
    });
}

function list_devices(Illuminate\Http\Request $request)
{
    // This will return a list of devices

    $order = $request->get('order');
    $type = $request->get('type');
    $query = $request->get('query');
    $param = [];

    if (is_string($order) && preg_match('/^([a-z_]+)(?: (desc|asc))?$/i', $order, $matches)) {
        $order = "d.`$matches[1]` " . ($matches[2] ?? 'ASC');
    } else {
        $order = 'd.`hostname` ASC';
    }

    $select = ' d.*, GROUP_CONCAT(dd.device_id) AS dependency_parent_id, GROUP_CONCAT(dd.hostname) AS dependency_parent_hostname, `location`, `lat`, `lng` ';
    $join = ' LEFT JOIN `device_relationships` AS dr ON dr.`child_device_id` = d.`device_id` LEFT JOIN `devices` AS dd ON dr.`parent_device_id` = dd.`device_id` LEFT JOIN `locations` ON `locations`.`id` = `d`.`location_id`';

    if ($type == 'all' || empty($type)) {
        $sql = '1';
    } elseif ($type == 'device_id') {
        $sql = '`d`.`device_id` = ?';
        $param[] = $query;
    } elseif ($type == 'active') {
        $sql = "`d`.`ignore`='0' AND `d`.`disabled`='0'";
    } elseif ($type == 'location') {
        $sql = '`locations`.`location` LIKE ?';
        $param[] = "%$query%";
    } elseif ($type == 'hostname') {
        $sql = '`d`.`hostname` LIKE ?';
        $param[] = "%$query%";
    } elseif ($type == 'ignored') {
        $sql = "`d`.`ignore`='1' AND `d`.`disabled`='0'";
    } elseif ($type == 'up') {
        $sql = "`d`.`status`='1' AND `d`.`ignore`='0' AND `d`.`disabled`='0'";
    } elseif ($type == 'down') {
        $sql = "`d`.`status`='0' AND `d`.`ignore`='0' AND `d`.`disabled`='0'";
    } elseif ($type == 'disabled') {
        $sql = "`d`.`disabled`='1'";
    } elseif ($type == 'os') {
        $sql = '`d`.`os`=?';
        $param[] = $query;
    } elseif ($type == 'mac') {
        $join .= ' LEFT JOIN `ports` AS p ON d.`device_id` = p.`device_id` LEFT JOIN `ipv4_mac` AS m ON p.`port_id` = m.`port_id` ';
        $sql = 'm.`mac_address`=?';
        $select .= ',p.* ';
        $param[] = $query;
    } elseif ($type == 'ipv4') {
        $join .= ' LEFT JOIN `ports` AS p ON d.`device_id` = p.`device_id` LEFT JOIN `ipv4_addresses` AS a ON p.`port_id` = a.`port_id` ';
        $sql = 'a.`ipv4_address`=?';
        $select .= ',p.* ';
        $param[] = $query;
    } elseif ($type == 'ipv6') {
        $join .= ' LEFT JOIN `ports` AS p ON d.`device_id` = p.`device_id` LEFT JOIN `ipv6_addresses` AS a ON p.`port_id` = a.`port_id` ';
        $sql = 'a.`ipv6_address`=? OR a.`ipv6_compressed`=?';
        $select .= ',p.* ';
        $param = [$query, $query];
    } elseif ($type == 'sysName') {
        $sql = '`d`.`sysName`=?';
        $param[] = $query;
    } elseif ($type == 'location_id') {
        $sql = '`d`.`location_id`=?';
        $param[] = $query;
    } elseif ($type == 'type') {
        $sql = '`d`.`type`=?';
        $param[] = $query;
    } elseif ($type == 'display') {
        $sql = '`d`.`display` LIKE ?';
        $param[] = "%$query%";
    } elseif (in_array($type, ['serial', 'version', 'hardware', 'features'])) {
        $sql = "`d`.`$type` LIKE ?";
        $param[] = "%$query%";
    } else {
        $sql = '1';
    }

    if (! Auth::user()->hasGlobalRead()) {
        $sql .= ' AND `d`.`device_id` IN (SELECT device_id FROM devices_perms WHERE user_id = ?)';
        $param[] = Auth::id();
    }
    $devices = [];
    $dev_query = "SELECT $select FROM `devices` AS d $join WHERE $sql GROUP BY d.`hostname` ORDER BY $order";
    foreach (dbFetchRows($dev_query, $param) as $device) {
        $host_id = get_vm_parent_id($device);
        $device['ip'] = inet6_ntop($device['ip']);
        if (is_numeric($host_id)) {
            $device['parent_id'] = $host_id;
        }
        $devices[] = $device;
    }

    return api_success($devices, 'devices');
}

function add_device(Illuminate\Http\Request $request)
{
    // This will add a device using the data passed encoded with json
    $data = $request->json()->all();

    if (empty($data)) {
        return api_error(400, 'No information has been provided to add this new device');
    }
    if (empty($data['hostname'])) {
        return api_error(400, 'Missing the device hostname');
    }

    try {
        $device = new Device(Arr::only($data, [
            'hostname',
            'display',
            'overwrite_ip',
            'location_id',
            'override_sysLocation',
            'port',
            'transport',
            'poller_group',
            'snmpver',
            'port_association_mode',
            'community',
            'authlevel',
            'authname',
            'authpass',
            'authalgo',
            'cryptopass',
            'cryptoalgo',
        ]));

        if (! empty($data['location'])) {
            $device->location_id = \App\Models\Location::firstOrCreate(['location' => $data['location']])->id;
        }

        // uses different name in legacy call
        if (! empty($data['version'])) {
            $device->snmpver = $data['version'];
        }

        $force_add = ! empty($data['force_add']);

        if (! empty($data['snmp_disable'])) {
            $device->os = $data['os'] ?? 'ping';
            $device->sysName = $data['sysName'] ?? '';
            $device->hardware = $data['hardware'] ?? '';
            $device->snmp_disable = 1;
        } elseif ($force_add && ! $device->hasSnmpInfo()) {
            return api_error(400, 'SNMP information is required when force adding a device');
        }

        (new ValidateDeviceAndCreate($device, $force_add, ! empty($data['ping_fallback'])))->execute();
    } catch (Exception $e) {
        return api_error(500, $e->getMessage());
    }

    $message = "Device $device->hostname ($device->device_id) has been added successfully";

    return api_success([$device->attributesToArray()], 'devices', $message);
}

function del_device(Illuminate\Http\Request $request)
{
    // This will add a device using the data passed encoded with json
    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(400, 'No hostname has been provided to delete');
    }

    // allow deleting by device_id or hostname
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $device = null;
    if ($device_id) {
        // save the current details for returning to the client on successful delete
        $device = device_by_id_cache($device_id);
    }

    if (! $device) {
        return api_error(404, "Device $hostname not found");
    }

    $response = delete_device($device_id);
    if (empty($response)) {
        // FIXME: Need to provide better diagnostics out of delete_device
        return api_error(500, 'Device deletion failed');
    }

    // deletion succeeded - include old device details in response
    return api_success([$device], 'devices', $response);
}

function maintenance_device(Illuminate\Http\Request $request)
{
    $data = $request->json()->all();

    if (empty($data)) {
        return api_error(400, 'No information has been provided to set this device into maintenance');
    }

    $hostname = $request->route('hostname');

    // use hostname as device_id if it's all digits
    $device = ctype_digit($hostname) ? Device::find($hostname) : Device::findByHostname($hostname);

    if (is_null($device)) {
        return api_error(404, "Device $hostname not found");
    }

    if (empty($data['duration'])) {
        return api_error(400, 'Duration not provided');
    }

    empty($data['notes']) ? $notes = '' : $notes = $data['notes'];
    $title = $data['title'] ?? $device->displayName();
    $behavior = MaintenanceBehavior::tryFrom((int) ($data['behavior'] ?? -1))
        ?? LibrenmsConfig::get('alert.scheduled_maintenance_default_behavior');

    $alert_schedule = new \App\Models\AlertSchedule([
        'title' => $title,
        'notes' => $notes,
        'behavior' => $behavior,
        'recurring' => 0,
    ]);

    $start = $data['start'] ?? \Carbon\Carbon::now()->format('Y-m-d H:i:00');
    $alert_schedule->start = $start;

    $duration = $data['duration'];

    if (Str::contains($duration, ':')) {
        [$duration_hour, $duration_min] = explode(':', $duration);
        $alert_schedule->end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $start)
            ->addHours((float) $duration_hour)->addMinutes((float) $duration_min)
            ->format('Y-m-d H:i:00');
    }

    $alert_schedule->save();
    $alert_schedule->devices()->attach($device);

    if ($notes && UserPref::getPref(Auth::user(), 'add_schedule_note_to_device')) {
        $device->notes .= (empty($device->notes) ? '' : PHP_EOL) . date('Y-m-d H:i') . ' Alerts delayed: ' . $notes;
        $device->save();
    }

    if (isset($data['start'])) {
        return api_success_noresult(201, "Device {$device->hostname} ({$device->device_id}) will begin maintenance mode at $start" . ($duration ? " for {$duration}h" : ''));
    } else {
        return api_success_noresult(201, "Device {$device->hostname} ({$device->device_id}) moved into maintenance mode" . ($duration ? " for {$duration}h" : ''));
    }
}

function device_under_maintenance(Illuminate\Http\Request $request)
{
    // return whether or not a device is in an active maintenance window

    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(400, 'No hostname has been provided to get maintenance status');
    }

    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $model = null;
    if ($device_id) {
        $model = DeviceCache::get((int) $device_id);
    }

    if (! $model) {
        return api_error(404, "Device $hostname not found");
    }

    return check_device_permission($device_id, function () use ($model) {
        return api_success($model->isUnderMaintenance(), 'is_under_maintenance');
    });
}

function device_availability(Illuminate\Http\Request $request)
{
    // return availability per device

    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(400, 'No hostname has been provided to get availability');
    }

    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) {
        $availabilities = Availability::select('duration', 'availability_perc')
                      ->where('device_id', '=', $device_id)
                      ->orderBy('duration', 'ASC');

        return api_success($availabilities->get(), 'availability');
    });
}

function device_outages(Illuminate\Http\Request $request)
{
    // return outages per device

    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(400, 'No hostname has been provided to get availability');
    }

    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) {
        $outages = DeviceOutage::select('going_down', 'up_again')
                   ->where('device_id', '=', $device_id)
                   ->orderBy('going_down', 'DESC');

        return api_success($outages->get(), 'outages');
    });
}

function get_vlans(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(500, 'No hostname has been provided');
    }

    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $device = null;
    if ($device_id) {
        // save the current details for returning to the client on successful delete
        $device = device_by_id_cache($device_id);
    }

    if (! $device) {
        return api_error(404, "Device $hostname not found");
    }

    return check_device_permission($device_id, function ($device_id) {
        $vlans = dbFetchRows('SELECT vlan_vlan,vlan_domain,vlan_name,vlan_type,vlan_state FROM vlans WHERE `device_id` = ?', [$device_id]);

        return api_success($vlans, 'vlans');
    });
}

function show_endpoints(Illuminate\Http\Request $request, Router $router)
{
    $output = [];
    $base = str_replace('api/v0', '', $request->url());
    foreach ($router->getRoutes() as $route) {
        /** @var \Illuminate\Routing\Route $route */
        if (Str::startsWith($route->getPrefix(), 'api/v0') && $route->getName()) {
            $output[$route->getName()] = $base . $route->uri();
        }
    }

    ksort($output);

    return response()->json($output, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function list_bgp(Illuminate\Http\Request $request)
{
    $sql = '';
    $sql_params = [];
    $hostname = $request->get('hostname');
    $asn = $request->get('asn');
    $remote_asn = $request->get('remote_asn');
    $local_address = $request->get('local_address');
    $remote_address = $request->get('remote_address');
    $bgp_descr = $request->get('bgp_descr');
    $bgp_state = $request->get('bgp_state');
    $bgp_adminstate = $request->get('bgp_adminstate');
    $bgp_family = $request->get('bgp_family');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    if (is_numeric($device_id)) {
        $sql .= ' AND `devices`.`device_id` = ?';
        $sql_params[] = $device_id;
    }
    if (! empty($asn)) {
        $sql .= ' AND `devices`.`bgpLocalAs` = ?';
        $sql_params[] = preg_replace('/[^0-9]/', '', $asn);
    }
    if (! empty($remote_asn)) {
        $sql .= ' AND `bgpPeers`.`bgpPeerRemoteAs` = ?';
        $sql_params[] = preg_replace('/[^0-9]/', '', $remote_asn);
    }
    if (! empty($local_address)) {
        $sql .= ' AND `bgpPeers`.`bgpLocalAddr` = ?';
        try {
            $sql_params[] = IP::parse($local_address)->uncompressed();
        } catch (InvalidIpException $e) {
            return api_error(400, 'Invalid local address');
        }
    }
    if (! empty($remote_address)) {
        $sql .= ' AND `bgpPeers`.`bgpPeerIdentifier` = ?';
        try {
            $sql_params[] = IP::parse($remote_address)->uncompressed();
        } catch (InvalidIpException $e) {
            return api_error(400, 'Invalid remote address');
        }
    }
    if (! empty($bgp_descr)) {
        $sql .= ' AND `bgpPeers`.`bgpPeerDescr` LIKE ?';
        $sql_params[] = "%$bgp_descr%";
    }
    if (! empty($bgp_state)) {
        $sql .= ' AND `bgpPeers`.`bgpPeerState` = ?';
        $sql_params[] = $bgp_state;
    }
    if (! empty($bgp_adminstate)) {
        $sql .= ' AND `bgpPeers`.`bgpPeerAdminStatus` = ?';
        $sql_params[] = $bgp_adminstate;
    }
    if (! empty($bgp_family)) {
        if ($bgp_family == 4) {
            $sql .= ' AND `bgpPeers`.`bgpLocalAddr` LIKE \'%.%\'';
        } elseif ($bgp_family == 6) {
            $sql .= ' AND `bgpPeers`.`bgpLocalAddr` LIKE \'%:%\'';
        }
    }

    $bgp_sessions = dbFetchRows("SELECT `bgpPeers`.* FROM `bgpPeers` LEFT JOIN `devices` ON `bgpPeers`.`device_id` = `devices`.`device_id` WHERE `bgpPeerState` IS NOT NULL AND `bgpPeerState` != '' $sql", $sql_params);
    $total_bgp_sessions = count($bgp_sessions);
    if (! is_numeric($total_bgp_sessions)) {
        return api_error(500, 'Error retrieving bgpPeers');
    }

    return api_success($bgp_sessions, 'bgp_sessions');
}

function get_bgp(Illuminate\Http\Request $request)
{
    $bgpPeerId = $request->route('id');
    if (! is_numeric($bgpPeerId)) {
        return api_error(400, 'Invalid id has been provided');
    }

    $bgp_session = dbFetchRows("SELECT * FROM `bgpPeers` WHERE `bgpPeerState` IS NOT NULL AND `bgpPeerState` != '' AND bgpPeer_id = ?", [$bgpPeerId]);
    $bgp_session_count = count($bgp_session);
    if (! is_numeric($bgp_session_count)) {
        return api_error(500, 'Error retrieving BGP peer');
    }
    if ($bgp_session_count == 0) {
        return api_error(404, "BGP peer $bgpPeerId does not exist");
    }

    return api_success($bgp_session, 'bgp_session');
}

function edit_bgp_descr(Illuminate\Http\Request $request)
{
    $bgp_descr = $request->json('bgp_descr');
    if (! $bgp_descr) {
        return api_error(500, 'Invalid JSON data');
    }

    //find existing bgp for update
    $bgpPeerId = $request->route('id');
    if (! is_numeric($bgpPeerId)) {
        return api_error(400, 'Invalid id has been provided');
    }

    $peer = \App\Models\BgpPeer::firstWhere('bgpPeer_id', $bgpPeerId);

    // update existing bgp
    if ($peer === null) {
        return api_error(404, 'BGP peer ' . $bgpPeerId . ' does not exist');
    }

    $peer->bgpPeerDescr = $bgp_descr;

    if ($peer->save()) {
        return api_success_noresult(200, 'BGP description for peer ' . $peer->bgpPeerIdentifier . ' on device ' . $peer->device_id . ' updated to ' . $peer->bgpPeerDescr . '.');
    }

    return api_error(500, 'Failed to update existing bgp');
}

function list_cbgp(Illuminate\Http\Request $request)
{
    $sql = '';
    $sql_params = [];
    $hostname = $request->get('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    if (is_numeric($device_id)) {
        $permission = check_device_permission($device_id);
        if ($permission !== true) {
            return $permission; // permission error
        }
        $sql = ' AND `devices`.`device_id` = ?';
        $sql_params[] = $device_id;
    }
    if (! Auth::user()->hasGlobalRead()) {
        $sql .= ' AND `bgpPeers_cbgp`.`device_id` IN (SELECT device_id FROM devices_perms WHERE user_id = ?)';
        $sql_params[] = Auth::id();
    }

    $bgp_counters = dbFetchRows("SELECT `bgpPeers_cbgp`.* FROM `bgpPeers_cbgp` LEFT JOIN `devices` ON `bgpPeers_cbgp`.`device_id` = `devices`.`device_id` WHERE `bgpPeers_cbgp`.`device_id` IS NOT NULL $sql", $sql_params);
    $total_bgp_counters = count($bgp_counters);
    if ($total_bgp_counters == 0) {
        return api_error(404, 'BGP counters does not exist');
    }

    return api_success($bgp_counters, 'bgp_counters');
}

function list_ospf(Illuminate\Http\Request $request)
{
    $sql = '';
    $sql_params = [];
    $hostname = $request->get('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    if (is_numeric($device_id)) {
        $sql = ' AND `device_id`=?';
        $sql_params = [$device_id];
    }

    $ospf_neighbours = dbFetchRows("SELECT * FROM ospf_nbrs WHERE `ospfNbrState` IS NOT NULL AND `ospfNbrState` != '' $sql", $sql_params);
    $total_ospf_neighbours = count($ospf_neighbours);
    if (! is_numeric($total_ospf_neighbours)) {
        return api_error(500, 'Error retrieving ospf_nbrs');
    }

    return api_success($ospf_neighbours, 'ospf_neighbours');
}

function list_ospf_ports(Illuminate\Http\Request $request)
{
    $ospf_ports = OspfPort::hasAccess(Auth::user())
              ->get();
    if ($ospf_ports->isEmpty()) {
        return api_error(404, 'Ospf ports do not exist');
    }

    return api_success($ospf_ports, 'ospf_ports', null, 200, $ospf_ports->count());
}

function list_ospfv3(Illuminate\Http\Request $request)
{
    $hostname = $request->get('hostname');
    $device_id = \App\Facades\DeviceCache::get($hostname)->device_id;

    $ospf_neighbours = Ospfv3Nbr::hasAccess(Auth::user())
        ->when($device_id, fn ($q) => $q->where('device_id', $device_id))
        ->whereNotNull('ospfv3NbrState')->where('ospfv3NbrState', '!=', '')
        ->get();

    if ($ospf_neighbours->isEmpty()) {
        return api_error(500, 'Error retrieving ospfv3_nbrs');
    }

    return api_success($ospf_neighbours, 'ospfv3_neighbours', count: $ospf_neighbours->count());
}

function list_ospfv3_ports(Illuminate\Http\Request $request)
{
    $hostname = $request->get('hostname');
    $device_id = \App\Facades\DeviceCache::get($hostname)->device_id;

    $ospf_ports = Ospfv3Port::hasAccess(Auth::user())
        ->when($device_id, fn ($q) => $q->where('device_id', $device_id))
        ->get();
    if ($ospf_ports->isEmpty()) {
        return api_error(404, 'Ospfv3 ports do not exist');
    }

    return api_success($ospf_ports, 'ospfv3_ports', count: $ospf_ports->count());
}

function get_graph_by_portgroup(Request $request)
{
    $id = $request->route('id');

    if (empty($id)) {
        $group = $request->route('group');
        $ports = get_ports_from_type(explode(',', $group));
        $if_list = implode(',', Arr::pluck($ports, 'port_id'));
    } else {
        $if_list = $id;
    }

    return api_get_graph($request, [
        'type' => 'multiport_bits_separate',
        'id' => $if_list,
    ]);
}

function get_components(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');

    // Do some filtering if the user requests.
    $options = [];
    // Add the rest of the options with an equals query
    foreach ($request->all() as $k => $v) {
        $options['filter'][$k] = ['=', $v];
    }

    // We need to specify the label as this is a LIKE query
    if ($request->has('label')) {
        // set a label like filter
        $options['filter']['label'] = ['LIKE', $request->get('label')];
    }

    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) use ($options) {
        $COMPONENT = new LibreNMS\Component();
        $components = $COMPONENT->getComponents($device_id, $options);

        return api_success($components[$device_id], 'components');
    });
}

function add_components(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $ctype = $request->route('type');

    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $COMPONENT = new LibreNMS\Component();
    $component = $COMPONENT->createComponent($device_id, $ctype);

    return api_success($component, 'components');
}

function edit_components(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $data = json_decode($request->getContent(), true);

    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $COMPONENT = new LibreNMS\Component();

    if (! $COMPONENT->setComponentPrefs($device_id, $data)) {
        return api_error(500, 'Components could not be edited.');
    }

    return api_success_noresult(200);
}

function delete_components(Illuminate\Http\Request $request)
{
    $cid = $request->route('component');

    $COMPONENT = new LibreNMS\Component();
    if ($COMPONENT->deleteComponent($cid)) {
        return api_success_noresult(200);
    } else {
        return api_error(500, 'Components could not be deleted.');
    }
}

function get_graphs(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');

    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) {
        $graphs = [];
        $graphs[] = [
            'desc' => 'Poller Time',
            'name' => 'device_poller_perf',
        ];
        $graphs[] = [
            'desc' => 'Ping Response',
            'name' => 'device_icmp_perf',
        ];
        foreach (dbFetchRows('SELECT * FROM device_graphs WHERE device_id = ? ORDER BY graph', [$device_id]) as $graph) {
            $desc = LibrenmsConfig::get("graph_types.device.{$graph['graph']}.descr");
            $graphs[] = [
                'desc' => $desc,
                'name' => 'device_' . $graph['graph'],
            ];
        }

        return api_success($graphs, 'graphs');
    });
}

function trigger_device_discovery(Illuminate\Http\Request $request)
{
    // return details of a single device
    $hostname = $request->route('hostname');

    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    // find device matching the id
    $device = device_by_id_cache($device_id);
    if (! $device) {
        return api_error(404, "Device $hostname does not exist");
    }

    $ret = device_discovery_trigger($device_id);

    return api_success($ret, 'result');
}

function list_available_health_graphs(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) use ($request) {
        $input_type = $request->route('type');
        if ($input_type) {
            $type = preg_replace('/^device_/', '', $input_type);
        }
        $sensor_id = $request->route('sensor_id');
        $graphs = [];

        if (isset($type)) {
            if (isset($sensor_id)) {
                $graphs = dbFetchRows('SELECT * FROM `sensors` WHERE `sensor_id` = ?', [$sensor_id]);
            } else {
                foreach (dbFetchRows('SELECT `sensor_id`, `sensor_descr` FROM `sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `sensor_deleted` = 0', [$device_id, $type]) as $graph) {
                    $graphs[] = [
                        'sensor_id' => $graph['sensor_id'],
                        'desc' => $graph['sensor_descr'],
                    ];
                }
            }
        } else {
            foreach (dbFetchRows('SELECT `sensor_class` FROM `sensors` WHERE `device_id` = ? AND `sensor_deleted` = 0 GROUP BY `sensor_class`', [$device_id]) as $graph) {
                $graphs[] = [
                    'desc' => ucfirst($graph['sensor_class']),
                    'name' => 'device_' . $graph['sensor_class'],
                ];
            }
            $device = Device::find($device_id);

            if ($device) {
                if ($device->processors()->count() > 0) {
                    array_push($graphs, [
                        'desc' => 'Processors',
                        'name' => 'device_processor',
                    ]);
                }

                if ($device->storage()->count() > 0) {
                    array_push($graphs, [
                        'desc' => 'Storage',
                        'name' => 'device_storage',
                    ]);
                }

                if ($device->mempools()->count() > 0) {
                    array_push($graphs, [
                        'desc' => 'Memory Pools',
                        'name' => 'device_mempool',
                    ]);
                }
            }
        }

        return api_success($graphs, 'graphs');
    });
}

function list_available_wireless_graphs(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) use ($request) {
        $input_type = $request->route('type');
        if ($input_type) {
            [, , $type] = explode('_', $input_type);
        }
        $sensor_id = $request->route('sensor_id');
        $graphs = [];

        if (isset($type)) {
            if (isset($sensor_id)) {
                $graphs = dbFetchRows('SELECT * FROM `wireless_sensors` WHERE `sensor_id` = ?', [$sensor_id]);
            } else {
                foreach (dbFetchRows('SELECT `sensor_id`, `sensor_descr` FROM `wireless_sensors` WHERE `device_id` = ? AND `sensor_class` = ? AND `sensor_deleted` = 0', [$device_id, $type]) as $graph) {
                    $graphs[] = [
                        'sensor_id' => $graph['sensor_id'],
                        'desc' => $graph['sensor_descr'],
                    ];
                }
            }
        } else {
            foreach (dbFetchRows('SELECT `sensor_class` FROM `wireless_sensors` WHERE `device_id` = ? AND `sensor_deleted` = 0 GROUP BY `sensor_class`', [$device_id]) as $graph) {
                $graphs[] = [
                    'desc' => ucfirst($graph['sensor_class']),
                    'name' => 'device_wireless_' . $graph['sensor_class'],
                ];
            }
        }

        return api_success($graphs, 'graphs');
    });
}

/**
 * @throws \LibreNMS\Exceptions\ApiException
 */
function get_port_graphs(Illuminate\Http\Request $request): JsonResponse
{
    $device = DeviceCache::get($request->route('hostname'));
    $columns = validate_column_list($request->get('columns'), 'ports', ['ifName']);

    $ports = $device->ports()->isNotDeleted()->hasAccess(Auth::user())
        ->select($columns)->orderBy('ifIndex')->get();

    if ($ports->isEmpty()) {
        return api_error(404, 'No ports found');
    }

    return api_success($ports, 'ports');
}

function get_device_ip_addresses(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) {
        $ipv4 = dbFetchRows('SELECT `ipv4_addresses`.* FROM `ipv4_addresses` JOIN `ports` ON `ports`.`port_id`=`ipv4_addresses`.`port_id` WHERE `ports`.`device_id` = ? AND `deleted` = 0', [$device_id]);
        $ipv6 = dbFetchRows('SELECT `ipv6_addresses`.* FROM `ipv6_addresses` JOIN `ports` ON `ports`.`port_id`=`ipv6_addresses`.`port_id` WHERE `ports`.`device_id` = ? AND `deleted` = 0', [$device_id]);
        $ip_addresses_count = count(array_merge($ipv4, $ipv6));
        if ($ip_addresses_count == 0) {
            return api_error(404, "Device $device_id does not have any IP addresses");
        }

        return api_success(array_merge($ipv4, $ipv6), 'addresses');
    });
}

function get_port_ip_addresses(Illuminate\Http\Request $request)
{
    $port_id = $request->route('portid');

    return check_port_permission($port_id, null, function ($port_id) {
        $ipv4 = dbFetchRows('SELECT * FROM `ipv4_addresses` WHERE `port_id` = ?', [$port_id]);
        $ipv6 = dbFetchRows('SELECT * FROM `ipv6_addresses` WHERE `port_id` = ?', [$port_id]);
        $ip_addresses_count = count(array_merge($ipv4, $ipv6));
        if ($ip_addresses_count == 0) {
            return api_error(404, "Port $port_id does not have any IP addresses");
        }

        return api_success(array_merge($ipv4, $ipv6), 'addresses');
    });
}

function get_port_fdb(Illuminate\Http\Request $request)
{
    $port_id = $request->route('portid');

    return check_port_permission($port_id, null, function ($port_id) {
        $fdb = PortsFdb::where('port_id', $port_id)->get();

        if ($fdb->isEmpty()) {
            return api_error(404, "Port {$port_id} does not have any MAC addresses in fdb");
        }

        return api_success($fdb, 'macs');
    });
}

function get_network_ip_addresses(Illuminate\Http\Request $request)
{
    $network_id = $request->route('id');
    $ipv4 = dbFetchRows('SELECT * FROM `ipv4_addresses` WHERE `ipv4_network_id` = ?', [$network_id]);
    $ipv6 = dbFetchRows('SELECT * FROM `ipv6_addresses` WHERE `ipv6_network_id` = ?', [$network_id]);
    $ip_addresses_count = count(array_merge($ipv4, $ipv6));
    if ($ip_addresses_count == 0) {
        return api_error(404, "IP network $network_id does not exist or is empty");
    }

    return api_success(array_merge($ipv4, $ipv6), 'addresses');
}

function get_port_transceiver(Illuminate\Http\Request $request)
{
    $port_id = $request->route('portid');

    return check_port_permission($port_id, null, function ($port_id) {
        $transceivers = Port::find($port_id)->transceivers()->get();

        return api_success($transceivers, 'transceivers');
    });
}

function get_port_info(Illuminate\Http\Request $request)
{
    $port_id = $request->route('portid');

    return check_port_permission($port_id, null, function ($port_id) {
        $with = request()->input('with');
        $allowed = ['vlans', 'device'];
        $port = Port::where('port_id', $port_id)
                    ->when(in_array($with, $allowed), fn ($q) => $q->with($with))
                    ->get();

        return api_success($port, 'port');
    });
}

function update_port_description(Illuminate\Http\Request $request)
{
    $port_id = $request->route('portid');
    $port = Port::hasAccess(Auth::user())
        ->where([
            'port_id' => $port_id,
        ])->first();
    if (empty($port)) {
        return api_error(400, 'Invalid port ID.');
    }

    $data = json_decode($request->getContent(), true);
    $field = 'description';
    $description = $data[$field];

    if (empty($description)) {
        // from update-ifalias.inc.php:
        // "Set to repoll so we avoid using ifDescr on port poll"
        $description = 'repoll';
    }

    $port->ifAlias = $description;
    $port->save();

    $ifName = $port->ifName;
    $device = $port->device_id;

    if ($description == 'repoll') {
        // No description provided, clear description
        del_dev_attrib($port, 'ifName:' . $ifName); // "port" object has required device_id
        Eventlog::log("$ifName Port ifAlias cleared via API", $device, 'interface', Severity::Notice, $port_id);

        return api_success_noresult(200, 'Port description cleared.');
    } else {
        // Prevent poller from overwriting new description
        set_dev_attrib($port, 'ifName:' . $ifName, 1); // see above
        Eventlog::log("$ifName Port ifAlias set via API: $description", $device, 'interface', Severity::Notice, $port_id);

        return api_success_noresult(200, 'Port description updated.');
    }
}

function get_port_description(Illuminate\Http\Request $request)
{
    $port_id = $request->route('portid');
    $port = Port::hasAccess(Auth::user())
        ->where([
            'port_id' => $port_id,
        ])->first();
    if (empty($port)) {
        return api_error(400, 'Invalid port ID.');
    } else {
        return api_success($port->ifAlias, 'port_description');
    }
}

/**
 * @throws \LibreNMS\Exceptions\ApiException
 */
function search_ports(Illuminate\Http\Request $request): JsonResponse
{
    $columns = validate_column_list($request->get('columns'), 'ports', ['device_id', 'port_id', 'ifIndex', 'ifName', 'ifAlias']);
    $field = $request->route('field');
    $search = $request->route('search');

    // if only field is set, swap values
    if (empty($search)) {
        [$field, $search] = [$search, $field];
    }
    $fields = validate_column_list($field, 'ports', ['ifAlias', 'ifDescr', 'ifName']);

    $ports = Port::hasAccess(Auth::user())
        ->isNotDeleted()
        ->where(function ($query) use ($fields, $search) {
            foreach ($fields as $field) {
                $query->orWhere($field, 'like', "%$search%");
            }
        })
        ->select($columns)
        ->orderBy('ifName')
        ->get();

    if ($ports->isEmpty()) {
        return api_error(404, 'No ports found');
    }

    return api_success($ports, 'ports');
}

/**
 * @throws \LibreNMS\Exceptions\ApiException
 */
function get_all_ports(Illuminate\Http\Request $request): JsonResponse
{
    $columns = validate_column_list($request->get('columns'), 'ports', ['port_id', 'ifName']);

    $ports = Port::hasAccess(Auth::user())
        ->select($columns)
        ->isNotDeleted()
        ->get();

    return api_success($ports, 'ports');
}

function get_port_stack(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $device = DeviceCache::get($hostname);

    return check_device_permission($device->device_id, function () use ($device) {
        return api_success($device->portsStack, 'mappings');
    });
}

function update_device_port_notes(Illuminate\Http\Request $request): JsonResponse
{
    $portid = $request->route('portid');

    $hostname = $request->route('hostname');
    // use hostname as device_id if it's all digits
    $device = \DeviceCache::get($hostname);

    $data = json_decode($request->getContent(), true);
    $field = 'notes';
    $content = $data[$field];
    if (empty($data)) {
        return api_error(400, 'Port field to patch has not been supplied.');
    }

    if ($device->setAttrib('port_id_notes:' . $portid, $content)) {
        return api_success_noresult(200, 'Port ' . $field . ' field has been updated');
    } else {
        return api_error(500, 'Port ' . $field . ' field failed to be updated');
    }
}

function list_alert_rules(Illuminate\Http\Request $request)
{
    $id = $request->route('id');

    $rules = \App\Http\Resources\AlertRule::collection(
        \App\Models\AlertRule::when($id, fn ($query) => $query->where('id', $id))
        ->with(['devices:device_id', 'groups:id', 'locations:id'])->get()
    );

    return api_success($rules->toArray($request), 'rules');
}

/**
 * @throws \LibreNMS\Exceptions\ApiException
 */
function list_alerts(Illuminate\Http\Request $request): JsonResponse
{
    $id = $request->route('id');

    $sql = 'SELECT `D`.`hostname`, `A`.*, `R`.`severity`,`R`.`name`,`R`.`proc`,`R`.`notes` FROM `alerts` AS `A`, `devices` AS `D`, `alert_rules` AS `R` WHERE `D`.`device_id` = `A`.`device_id` AND `A`.`rule_id` = `R`.`id` ';
    $sql .= 'AND `A`.`state` IN ';
    if ($request->has('state')) {
        $param = explode(',', $request->get('state'));
    } else {
        $param = [1];
    }
    $sql .= dbGenPlaceholders(count($param));

    if ($id > 0) {
        $param[] = $id;
        $sql .= 'AND `A`.id=?';
    }

    $severity = $request->get('severity');
    if ($severity) {
        if (in_array($severity, ['ok', 'warning', 'critical'])) {
            $param[] = $severity;
            $sql .= ' AND `R`.severity=?';
        }
    }

    $order = 'timestamp desc';

    $alert_rule = $request->get('alert_rule');
    if (isset($alert_rule)) {
        if (is_numeric($alert_rule)) {
            $param[] = $alert_rule;
            $sql .= ' AND `R`.id=?';
        }
    }

    if ($request->has('order')) {
        [$sort_column, $sort_order] = explode(' ', $request->get('order'), 2);
        validate_column_list($sort_column, 'alerts');
        if (in_array($sort_order, ['asc', 'desc'])) {
            $order = $request->get('order');
        }
    }
    $sql .= ' ORDER BY A.' . $order;

    $alerts = dbFetchRows($sql, $param);

    return api_success($alerts, 'alerts');
}

function add_edit_rule(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (json_last_error() || ! is_array($data)) {
        return api_error(500, "We couldn't parse the provided json");
    }

    $rule_id = $data['rule_id'];
    $tmp_devices = (array) $data['devices'];
    $groups = (array) $data['groups'];
    $locations = (array) $data['locations'];
    if (empty($tmp_devices) && ! isset($rule_id)) {
        return api_error(400, 'Missing the devices or global device (-1)');
    }

    $devices = [];
    foreach ($tmp_devices as $device) {
        if ($device == '-1') {
            continue;
        }
        $devices[] = (ctype_digit($device) || is_int($device)) ? $device : getidbyname($device);
    }

    if (isset($data['builder'])) {
        // accept inline json or json as a string
        $builder = is_array($data['builder']) ? json_encode($data['builder']) : $data['builder'];
    } else {
        $builder = $data['rule'];
    }
    if (empty($builder)) {
        return api_error(400, 'Missing the alert builder rule');
    }

    $name = $data['name'];
    if (empty($name)) {
        return api_error(400, 'Missing the alert rule name');
    }

    $severity = $data['severity'];
    $sevs = [
        'ok',
        'warning',
        'critical',
    ];
    if (! in_array($severity, $sevs)) {
        return api_error(400, 'Missing the severity');
    }

    $disabled = $data['disabled'];
    if ($disabled != '0' && $disabled != '1') {
        $disabled = 0;
    }

    $count = $data['count'];
    $mute = $data['mute'];
    $delay = $data['delay'];
    $interval = $data['interval'];
    $override_query = $data['override_query'];
    $adv_query = $data['adv_query'];
    $notes = $data['notes'];
    $delay_sec = convert_delay($delay);
    $interval_sec = convert_delay($interval);
    if ($mute == 1) {
        $mute = true;
    } else {
        $mute = false;
    }

    $extra = [
        'mute' => $mute,
        'count' => $count,
        'delay' => $delay_sec,
        'interval' => $interval_sec,
        'options' => [
            'override_query' => $override_query,
        ],
    ];
    $extra_json = json_encode($extra);

    if ($override_query === 'on') {
        $query = $adv_query;
    } else {
        $query = QueryBuilderParser::fromJson($builder)->toSql();
        if (empty($query)) {
            return api_error(500, "We couldn't parse your rule");
        }
    }

    if (! isset($rule_id)) {
        if (dbFetchCell('SELECT `name` FROM `alert_rules` WHERE `name`=?', [$name]) == $name) {
            return api_error(500, 'Addition failed : Name has already been used');
        }
    } elseif (dbFetchCell('SELECT name FROM alert_rules WHERE name=? AND id !=? ', [$name, $rule_id]) == $name) {
        return api_error(500, 'Update failed : Invalid rule id');
    }

    if (is_numeric($rule_id)) {
        if (! (dbUpdate(['name' => $name, 'builder' => $builder, 'query' => $query, 'severity' => $severity, 'disabled' => $disabled, 'extra' => $extra_json, 'notes' => $notes], 'alert_rules', 'id=?', [$rule_id]) >= 0)) {
            return api_error(500, 'Failed to update existing alert rule');
        }
    } elseif (! $rule_id = dbInsert(['name' => $name, 'builder' => $builder, 'query' => $query, 'severity' => $severity, 'disabled' => $disabled, 'extra' => $extra_json, 'notes' => $notes], 'alert_rules')) {
        return api_error(500, 'Failed to create new alert rule');
    }

    dbSyncRelationship('alert_device_map', 'rule_id', $rule_id, 'device_id', $devices);
    dbSyncRelationship('alert_group_map', 'rule_id', $rule_id, 'group_id', $groups);
    dbSyncRelationship('alert_location_map', 'rule_id', $rule_id, 'location_id', $locations);

    return api_success_noresult(200);
}

function delete_rule(Illuminate\Http\Request $request)
{
    $rule_id = $request->route('id');
    if (is_numeric($rule_id)) {
        if (dbDelete('alert_rules', '`id` =  ? LIMIT 1', [$rule_id])) {
            return api_success_noresult(200, 'Alert rule has been removed');
        } else {
            return api_success_noresult(200, 'No alert rule by that ID');
        }
    }

    return api_error(400, 'Invalid rule id has been provided');
}

function ack_alert(Illuminate\Http\Request $request)
{
    $alert_id = $request->route('id');
    $data = json_decode($request->getContent(), true);

    if (! is_numeric($alert_id)) {
        return api_error(400, 'Invalid alert has been provided');
    }

    $alert = dbFetchRow('SELECT note, info FROM alerts WHERE id=?', [$alert_id]);
    $note = $alert['note'];
    $info = json_decode($alert['info'], true);
    if (! empty($note)) {
        $note .= PHP_EOL;
    }
    $note .= date(LibrenmsConfig::get('dateformat.long')) . ' - Ack (' . Auth::user()->username . ") {$data['note']}";
    $info['until_clear'] = $data['until_clear'];
    $info = json_encode($info);

    if (dbUpdate(['state' => 2, 'note' => $note, 'info' => $info], 'alerts', '`id` = ? LIMIT 1', [$alert_id])) {
        return api_success_noresult(200, 'Alert has been acknowledged');
    } else {
        return api_success_noresult(200, 'No Alert by that ID');
    }
}

function unmute_alert(Illuminate\Http\Request $request)
{
    $alert_id = $request->route('id');
    $data = json_decode($request->getContent(), true);

    if (! is_numeric($alert_id)) {
        return api_error(400, 'Invalid alert has been provided');
    }

    $alert = dbFetchRow('SELECT note, info FROM alerts WHERE id=?', [$alert_id]);
    $note = $alert['note'];

    if (! empty($note)) {
        $note .= PHP_EOL;
    }
    $note .= date(LibrenmsConfig::get('dateformat.long')) . ' - Ack (' . Auth::user()->username . ") {$data['note']}";

    if (dbUpdate(['state' => 1, 'note' => $note], 'alerts', '`id` = ? LIMIT 1', [$alert_id])) {
        return api_success_noresult(200, 'Alert has been unmuted');
    } else {
        return api_success_noresult(200, 'No alert by that ID');
    }
}

function get_inventory(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) use ($request) {
        $sql = '';
        $params = [];
        if ($request->get('entPhysicalClass')) {
            $sql .= ' AND entPhysicalClass=?';
            $params[] = $request->get('entPhysicalClass');
        }

        if ($request->get('entPhysicalContainedIn')) {
            $sql .= ' AND entPhysicalContainedIn=?';
            $params[] = $request->get('entPhysicalContainedIn');
        } else {
            $sql .= ' AND entPhysicalContainedIn="0"';
        }

        if (! is_numeric($device_id)) {
            return api_error(400, 'Invalid device provided');
        }
        $sql .= ' AND `device_id`=?';
        $params[] = $device_id;
        $inventory = dbFetchRows("SELECT * FROM `entPhysical` WHERE 1 $sql", $params);

        return api_success($inventory, 'inventory');
    });
}

function get_inventory_for_device(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    return check_device_permission($device_id, function ($device_id) {
        $params = [];
        $sql = 'SELECT * FROM `entPhysical` WHERE device_id = ?';
        $params[] = $device_id;
        $inventory = dbFetchRows($sql, $params);

        return api_success($inventory, 'inventory');
    });
}

function search_oxidized(Illuminate\Http\Request $request)
{
    $search_in_conf_textbox = $request->route('searchstring');
    $result = search_oxidized_config($search_in_conf_textbox);

    if (! $result) {
        return api_error(404, 'Received no data from Oxidized');
    } else {
        return api_success($result, 'nodes');
    }
}

function get_oxidized_config(Illuminate\Http\Request $request)
{
    $hostname = $request->route('device_name');
    $node_info = json_decode((new \App\ApiClients\Oxidized())->getContent('/node/show/' . $hostname . '?format=json'), true);
    $result = json_decode((new \App\ApiClients\Oxidized())->getContent('/node/fetch/' . $node_info['full_name'] . '?format=json'), true);
    if (! $result) {
        return api_error(404, 'Received no data from Oxidized');
    } else {
        return api_success($result, 'config');
    }
}

function list_oxidized(Illuminate\Http\Request $request)
{
    $return = [];
    $devices = Device::query()
            ->with('attribs')
             ->where('disabled', 0)
             ->when($request->route('hostname'), function ($query, $hostname) {
                 return $query->where('hostname', $hostname);
             })
             ->whereNotIn('type', LibrenmsConfig::get('oxidized.ignore_types', []))
             ->whereNotIn('os', LibrenmsConfig::get('oxidized.ignore_os', []))
             ->whereAttributeDisabled('override_Oxidized_disable')
             ->select(['devices.device_id', 'hostname', 'sysName', 'sysDescr', 'sysObjectID', 'hardware', 'os', 'ip', 'location_id', 'purpose', 'notes', 'poller_group'])
             ->get();

    /** @var Device $device */
    foreach ($devices as $device) {
        $output = [
            'hostname' => $device->hostname,
            'os' => $device->os,
            'ip' => $device->ip,
        ];
        $custom_ssh_port = $device->getAttrib('override_device_ssh_port');
        if (! empty($custom_ssh_port)) {
            $output['ssh_port'] = $custom_ssh_port;
        }
        $custom_telnet_port = $device->getAttrib('override_device_telnet_port');
        if (! empty($custom_telnet_port)) {
            $output['telnet_port'] = $custom_telnet_port;
        }
        // Pre-populate the group with the default
        if (LibrenmsConfig::get('oxidized.group_support') === true && ! empty(LibrenmsConfig::get('oxidized.default_group'))) {
            $output['group'] = LibrenmsConfig::get('oxidized.default_group');
        }

        foreach (LibrenmsConfig::get('oxidized.maps') as $maps_column => $maps) {
            // Based on Oxidized group support we can apply groups by setting group_support to true
            if ($maps_column == 'group' && LibrenmsConfig::get('oxidized.group_support', true) !== true) {
                continue;
            }

            foreach ($maps as $field_type => $fields) {
                if ($field_type == 'sysname') {
                    $value = $device->sysName; // fix typo in previous code forcing users to use sysname instead of sysName
                } elseif ($field_type == 'location') {
                    $value = $device->location->location;
                } else {
                    $value = $device->$field_type;
                }

                foreach ($fields as $field) {
                    if (isset($field['regex']) && preg_match($field['regex'] . 'i', (string) $value)) {
                        $output[$maps_column] = $field['value'] ?? $field[$maps_column];  // compatibility with old format
                        break;
                    } elseif (isset($field['match']) && $field['match'] == $value) {
                        $output[$maps_column] = $field['value'] ?? $field[$maps_column]; // compatibility with old format
                        break;
                    }
                }
            }
        }
        //Exclude groups from being sent to Oxidized
        if (in_array($output['group'], LibrenmsConfig::get('oxidized.ignore_groups'))) {
            continue;
        }

        $return[] = $output;
    }

    return response()->json($return, 200, [], JSON_PRETTY_PRINT);
}

function list_bills(Illuminate\Http\Request $request)
{
    $bills = [];
    $bill_id = $request->route('bill_id');
    $bill_ref = $request->get('ref');
    $bill_custid = $request->get('custid');
    $period = $request->get('period');
    $param = [];
    $sql = '';

    if (! empty($bill_custid)) {
        $sql .= '`bill_custid` = ?';
        $param[] = $bill_custid;
    } elseif (! empty($bill_ref)) {
        $sql .= '`bill_ref` = ?';
        $param[] = $bill_ref;
    } elseif (is_numeric($bill_id)) {
        $sql .= '`bill_id` = ?';
        $param[] = $bill_id;
    } else {
        $sql = '1';
    }
    if (! Auth::user()->hasGlobalRead()) {
        $sql .= ' AND `bill_id` IN (SELECT `bill_id` FROM `bill_perms` WHERE `user_id` = ?)';
        $param[] = Auth::id();
    }

    if ($period === 'previous') {
        $select = 'SELECT bills.bill_autoadded, bills.bill_cdr, bills.bill_custid, bills.bill_day, bills.bill_name,
            bills.bill_notes, bills.bill_quota, bills.bill_ref, bill_history.*, bill_history.traf_total as total_data,
            bill_history.traf_in as total_data_in, bill_history.traf_out as total_data_out, bill_history.updated as bill_last_calc
        ';
        $query = 'FROM `bills`
            INNER JOIN (SELECT bill_id, MAX(bill_hist_id) AS bill_hist_id FROM bill_history
                        WHERE bill_dateto < NOW() AND bill_dateto > subdate(NOW(), 40)
                        GROUP BY bill_id) qLastBills ON bills.bill_id = qLastBills.bill_id
            INNER JOIN bill_history ON qLastBills.bill_hist_id = bill_history.bill_hist_id
        ';
    } else {
        $select = "SELECT bills.*,
            IF(bills.bill_type = 'cdr', bill_cdr, bill_quota) AS bill_allowed
        ";
        $query = "FROM `bills`\n";
    }

    foreach (dbFetchRows("$select $query WHERE $sql ORDER BY `bill_name`", $param) as $bill) {
        $rate_data = $bill;
        $allowed = '';
        $used = '';
        $percent = '';
        $overuse = '';

        if (strtolower($bill['bill_type']) == 'cdr') {
            $allowed = Number::formatSi($bill['bill_cdr'], 2, 0, '') . 'bps';
            $used = Number::formatSi($rate_data['rate_95th'], 2, 0, '') . 'bps';
            if ($bill['bill_cdr'] > 0) {
                $percent = Number::calculatePercent($rate_data['rate_95th'], $bill['bill_cdr']);
            } else {
                $percent = '-';
            }
            $overuse = $rate_data['rate_95th'] - $bill['bill_cdr'];
            $overuse = (($overuse <= 0) ? '-' : Number::formatSi($overuse, 2, 0, ''));
        } elseif (strtolower($bill['bill_type']) == 'quota') {
            $allowed = Billing::formatBytes($bill['bill_quota']);
            $used = Billing::formatBytes($rate_data['total_data']);
            if ($bill['bill_quota'] > 0) {
                $percent = Number::calculatePercent($rate_data['total_data'], $bill['bill_quota']);
            } else {
                $percent = '-';
            }
            $overuse = $rate_data['total_data'] - $bill['bill_quota'];
            $overuse = (($overuse <= 0) ? '-' : Billing::formatBytes($overuse));
        }
        $bill['allowed'] = $allowed;
        $bill['used'] = $used;
        $bill['percent'] = $percent;
        $bill['overuse'] = $overuse;

        $bill['ports'] = dbFetchRows('SELECT `D`.`device_id`,`P`.`port_id`,`P`.`ifName` FROM `bill_ports` AS `B`, `ports` AS `P`, `devices` AS `D` WHERE `B`.`bill_id` = ? AND `P`.`port_id` = `B`.`port_id` AND `D`.`device_id` = `P`.`device_id`', [$bill['bill_id']]);

        $bills[] = $bill;
    }

    return api_success($bills, 'bills');
}

function get_bill_graph(Illuminate\Http\Request $request)
{
    $bill_id = $request->route('bill_id');
    $graph_type = $request->route('graph_type');
    if ($graph_type == 'monthly') {
        $graph_type = 'historicmonthly';
    }

    $vars = [
        'type' => "bill_$graph_type",
        'id' => $bill_id,
    ];

    return check_bill_permission($bill_id, function () use ($request, $vars) {
        return api_get_graph($request, $vars);
    });
}

function get_bill_graphdata(Illuminate\Http\Request $request)
{
    $bill_id = $request->route('bill_id');

    return check_bill_permission($bill_id, function ($bill_id) use ($request) {
        $graph_type = $request->route('graph_type');
        if ($graph_type == 'bits') {
            $from = $request->get('from', time() - 60 * 60 * 24);
            $to = $request->get('to', time());
            $reducefactor = $request->get('reducefactor');

            $graph_data = Billing::getBitsGraphData($bill_id, $from, $to, $reducefactor);
        } elseif ($graph_type == 'monthly') {
            $graph_data = Billing::getHistoricTransferGraphData($bill_id);
        }

        if (! isset($graph_data)) {
            return api_error(400, "Unsupported graph type $graph_type");
        } else {
            return api_success($graph_data, 'graph_data');
        }
    });
}

function get_bill_history(Illuminate\Http\Request $request)
{
    $bill_id = $request->route('bill_id');

    return check_bill_permission($bill_id, function ($bill_id) {
        $result = dbFetchRows('SELECT * FROM `bill_history` WHERE `bill_id` = ? ORDER BY `bill_datefrom` DESC LIMIT 24', [$bill_id]);

        return api_success($result, 'bill_history');
    });
}

function get_bill_history_graph(Illuminate\Http\Request $request)
{
    $bill_id = $request->route('bill_id');
    $bill_hist_id = $request->route('bill_hist_id');
    $graph_type = $request->route('graph_type');

    $vars = [
        'type' => "bill_$graph_type",
        'id' => $bill_id,
        'bill_hist_id' => $bill_hist_id,
    ];

    switch ($graph_type) {
        case 'bits':
            $vars['type'] = 'bill_historicbits';
            $vars['reducefactor'] = $request->get('reducefactor');
            break;

        case 'day':
        case 'hour':
            $vars['imgtype'] = $graph_type;
            $vars['type'] = 'bill_historictransfer';
            break;

        default:
            return api_error(400, "Unknown Graph Type $graph_type");
    }

    return check_bill_permission($bill_id, function () use ($request, $vars) {
        return api_get_graph($request, $vars);
    });
}

function get_bill_history_graphdata(Illuminate\Http\Request $request)
{
    $bill_id = $request->route('bill_id');

    return check_bill_permission($bill_id, function ($bill_id) use ($request) {
        $bill_hist_id = $request->route('bill_hist_id');
        $graph_type = $request->route('graph_type');

        switch ($graph_type) {
            case 'bits':
                $reducefactor = $request->get('reducefactor');

                $graph_data = Billing::getHistoryBitsGraphData($bill_id, $bill_hist_id, $reducefactor);
                break;
            case 'day':
            case 'hour':
                $graph_data = Billing::getBandwidthGraphData($bill_id, $bill_hist_id, null, null, $graph_type);
                break;
        }

        return ! isset($graph_data) ?
               api_error(400, "Unsupported graph type $graph_type") :
               api_success($graph_data, 'graph_data');
    });
}

function delete_bill(Illuminate\Http\Request $request)
{
    $bill_id = $request->route('bill_id');

    if ($bill_id < 1) {
        return api_error(400, 'Could not remove bill with id ' . $bill_id . '. Invalid id');
    }

    $res = dbDelete('bills', '`bill_id` =  ? LIMIT 1', [$bill_id]);
    if ($res == 1) {
        dbDelete('bill_ports', '`bill_id` =  ? ', [$bill_id]);
        dbDelete('bill_data', '`bill_id` =  ? ', [$bill_id]);
        dbDelete('bill_history', '`bill_id` =  ? ', [$bill_id]);
        dbDelete('bill_history', '`bill_id` =  ? ', [$bill_id]);
        dbDelete('bill_perms', '`bill_id` =  ? ', [$bill_id]);

        return api_success_noresult(200, 'Bill has been removed');
    }

    return api_error(400, 'Could not remove bill with id ' . $bill_id);
}

function check_bill_key_value($bill_key, $bill_value)
{
    $bill_types = ['quota', 'cdr'];

    switch ($bill_key) {
        case 'bill_type':
            if (! in_array($bill_value, $bill_types)) {
                return api_error(400, "Invalid value for $bill_key: $bill_value. Allowed: quota,cdr");
            }
            break;
        case 'bill_cdr':
            if (! is_numeric($bill_value)) {
                return api_error(400, "Invalid value for $bill_key. Must be numeric.");
            }
            break;
        case 'bill_day':
            if ($bill_value < 1 || $bill_value > 31) {
                return api_error(400, "Invalid value for $bill_key. range: 1-31");
            }
            break;
        case 'bill_quota':
            if (! is_numeric($bill_value)) {
                return api_error(400, "Invalid value for $bill_key. Must be numeric");
            }
            break;
        default:
    }

    return true;
}

function create_edit_bill(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (! $data) {
        return api_error(500, 'Invalid JSON data');
    }
    //check ports
    $ports_add = null;
    if (array_key_exists('ports', $data)) {
        $ports_add = [];
        $ports = $data['ports'];
        foreach ($ports as $port_id) {
            $result = dbFetchRows('SELECT port_id FROM `ports` WHERE `port_id` = ?  LIMIT 1', [$port_id]);
            $result = $result[0];
            if (! is_array($result) || ! array_key_exists('port_id', $result)) {
                return api_error(500, 'Port ' . $port_id . ' does not exists');
            }
            $ports_add[] = $port_id;
        }
    }

    $bill = [];
    //find existing bill for update
    $bill_id = (int) $data['bill_id'];
    $bills = dbFetchRows("SELECT * FROM `bills` WHERE `bill_id` = $bill_id LIMIT 1");

    // update existing bill
    if (is_array($bills) && count($bills) == 1) {
        $bill = $bills[0];

        foreach ($data as $bill_key => $bill_value) {
            $res = check_bill_key_value($bill_key, $bill_value);
            if ($res === true) {
                $bill[$bill_key] = $bill_value;
            } else {
                return $res;
            }
        }
        $update_data = [
            'bill_name' => $bill['bill_name'],
            'bill_type' => $bill['bill_type'],
            'bill_cdr' => $bill['bill_cdr'],
            'bill_day' => $bill['bill_day'],
            'bill_quota' => $bill['bill_quota'],
            'bill_custid' => $bill['bill_custid'],
            'bill_ref' => $bill['bill_ref'],
            'bill_notes' => $bill['bill_notes'],
            'dir_95th' => $bill['dir_95th'],
        ];
        $update = dbUpdate($update_data, 'bills', 'bill_id=?', [$bill_id]);
        if ($update === false || $update < 0) {
            return api_error(500, 'Failed to update existing bill');
        }
    } else {
        // create new bill
        if (array_key_exists('bill_id', $data)) {
            return api_error(500, 'Argument bill_id is not allowed on bill create (auto assigned)');
        }

        $bill_keys = [
            'bill_name',
            'bill_type',
            'bill_cdr',
            'bill_day',
            'bill_quota',
            'bill_custid',
            'bill_ref',
            'bill_notes',
            'dir_95th',
        ];

        if ($data['bill_type'] == 'quota') {
            $data['bill_cdr'] = 0;
        }
        if ($data['bill_type'] == 'cdr') {
            $data['bill_quota'] = 0;
        }

        $missing_keys = '';
        $missing = array_diff_key(array_flip($bill_keys), $data);
        if (count($missing) > 0) {
            foreach ($missing as $missing_key => $dummy) {
                $missing_keys .= " $missing_key";
            }

            return api_error(500, 'Missing parameters: ' . $missing_keys);
        }

        foreach ($bill_keys as $bill_key) {
            $res = check_bill_key_value($bill_key, $data[$bill_key]);
            if ($res === true) {
                $bill[$bill_key] = $data[$bill_key];
            } else {
                return $res;
            }
        }

        $bill_id = dbInsert(
            [
                'bill_name' => $bill['bill_name'],
                'bill_type' => $bill['bill_type'],
                'bill_cdr' => $bill['bill_cdr'],
                'bill_day' => $bill['bill_day'],
                'bill_quota' => $bill['bill_quota'],
                'bill_custid' => $bill['bill_custid'],
                'bill_ref' => $bill['bill_ref'],
                'bill_notes' => $bill['bill_notes'],
                'dir_95th' => $bill['dir_95th'],
            ],
            'bills'
        );

        if ($bill_id === null) {
            return api_error(500, 'Failed to create new bill');
        }
    }

    // set previously checked ports
    if (is_array($ports_add)) {
        dbDelete('bill_ports', "`bill_id` =  $bill_id");
        if (count($ports_add) > 0) {
            foreach ($ports_add as $port_id) {
                dbInsert(['bill_id' => $bill_id, 'port_id' => $port_id, 'bill_port_autoadded' => 0], 'bill_ports');
            }
        }
    }

    return api_success($bill_id, 'bill_id');
}

function update_device(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    // use hostname as device_id if it's all digits
    $device = ctype_digit($hostname) ? Device::find($hostname) : Device::findByHostname($hostname);

    if (is_null($device)) {
        return api_error(404, "Device $hostname not found");
    }

    $data = json_decode($request->getContent(), true);
    $bad_fields = ['device_id', 'hostname'];
    if (empty($data['field'])) {
        return api_error(400, 'Device field to patch has not been supplied');
    } elseif (in_array($data['field'], $bad_fields)) {
        return api_error(500, 'Device field is not allowed to be updated');
    }

    if (is_array($data['field']) && is_array($data['data'])) {
        foreach ($data['field'] as $tmp_field) {
            if (in_array($tmp_field, $bad_fields)) {
                return api_error(500, 'Device field is not allowed to be updated');
            }
        }
        if (count($data['field']) == count($data['data'])) {
            $update = [];
            for ($x = 0; $x < count($data['field']); $x++) {
                $field = $data['field'][$x];
                $field_data = $data['data'][$x];

                if ($field == 'location') {
                    $field = 'location_id';
                    $field_data = \App\Models\Location::firstOrCreate(['location' => $field_data])->id;
                }

                $update[$field] = $field_data;
            }
            if ($device->fill($update)->save()) {
                return api_success_noresult(200, 'Device fields have been updated');
            } else {
                return api_error(500, 'Device fields failed to be updated');
            }
        } else {
            return api_error(500, 'Device fields failed to be updated as the number of fields (' . count($data['field']) . ') does not match the supplied data (' . count($data['data']) . ')');
        }
    } elseif ($device->fill([$data['field'] => $data['data']])->save()) {
        return api_success_noresult(200, 'Device ' . $data['field'] . ' field has been updated');
    } else {
        return api_error(500, 'Device ' . $data['field'] . ' field failed to be updated');
    }
}

function rename_device(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $new_hostname = $request->route('new_hostname');
    $new_device = getidbyname($new_hostname);

    if (empty($new_hostname)) {
        return api_error(500, 'Missing new hostname');
    } elseif ($new_device) {
        return api_error(500, 'Device failed to rename, new hostname already exists');
    } else {
        if (renamehost($device_id, $new_hostname, 'api') == '') {
            return api_success_noresult(200, 'Device has been renamed');
        } else {
            return api_error(500, 'Device failed to be renamed');
        }
    }
}

function add_port_group(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    $rules = [
        'name' => 'required|string|unique:port_groups',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return api_error(422, $v->messages());
    }

    $portGroup = PortGroup::make(['name' => $data['name'], 'desc' => $data['desc']]);
    $portGroup->save();

    return api_success($portGroup->id, 'id', 'Port group ' . $portGroup->name . ' created', 201);
}

function get_port_groups(Illuminate\Http\Request $request)
{
    $query = PortGroup::query();

    $groups = $query->orderBy('name')->get();

    if ($groups->isEmpty()) {
        return api_error(404, 'No port groups found');
    }

    return api_success($groups->makeHidden('pivot')->toArray(), 'groups', 'Found ' . $groups->count() . ' port groups');
}

function get_ports_by_group(Illuminate\Http\Request $request)
{
    $name = $request->route('name');
    if (! $name) {
        return api_error(400, 'No port group name provided');
    }

    $port_group = ctype_digit($name) ? PortGroup::find($name) : PortGroup::where('name', $name)->first();

    if (empty($port_group)) {
        return api_error(404, 'Port group not found');
    }

    $ports = $port_group->ports()->get($request->get('full') ? ['*'] : ['ports.port_id']);

    if ($ports->isEmpty()) {
        return api_error(404, 'No ports found in group ' . $name);
    }

    return api_success($ports->makeHidden('pivot')->toArray(), 'ports');
}

function assign_port_group(Illuminate\Http\Request $request)
{
    $port_group_id = $request->route('port_group_id');
    $data = json_decode($request->getContent(), true);
    $port_id_list = $data['port_ids'];

    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    if (! isset($port_id_list)) {
        return api_error(400, "Missing data field 'port_ids' " . json_last_error_msg());
    }

    $port_group = PortGroup::find($port_group_id);
    if (! isset($port_group)) {
        return api_error(404, 'Port Group ID ' . $port_group_id . ' not found');
    }

    $port_group->ports()->attach($port_id_list);

    return api_success(200, 'Port Ids ' . implode(', ', $port_id_list) . ' have been added to Port Group Id ' . $port_group_id);
}

function remove_port_group(Illuminate\Http\Request $request)
{
    $port_group_id = $request->route('port_group_id');
    $data = json_decode($request->getContent(), true);
    $port_id_list = $data['port_ids'];

    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    if (! isset($port_id_list)) {
        return api_error(400, "Missing data field 'port_ids' " . json_last_error_msg());
    }

    $port_group = PortGroup::find($port_group_id);
    if (! isset($port_group)) {
        return api_error(404, 'Port Group ID ' . $port_group_id . ' not found');
    }

    $port_group->ports()->detach($port_id_list);

    return api_success(200, 'Port Ids ' . implode(', ', $port_id_list) . ' have been removed from Port Group Id ' . $port_group_id);
}

function add_device_group(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    $rules = [
        'name' => 'required|string|unique:device_groups',
        'type' => 'required|in:dynamic,static',
        'devices' => 'array|required_if:type,static',
        'devices.*' => 'integer',
        'rules' => 'json|required_if:type,dynamic',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return api_error(422, $v->messages());
    }

    if (! empty($data['rules'])) {
        // Only use the rules if they are able to be parsed by the QueryBuilder
        $query = QueryBuilderParser::fromJson($data['rules'])->toSql();
        if (empty($query)) {
            return api_error(500, "We couldn't parse your rule");
        }
    }

    $deviceGroup = DeviceGroup::make(['name' => $data['name'], 'type' => $data['type'], 'desc' => $data['desc']]);
    if ($data['type'] == 'dynamic') {
        $deviceGroup->rules = json_decode($data['rules']);
    }
    $deviceGroup->save();

    if ($data['type'] == 'static') {
        $deviceGroup->devices()->sync($data['devices']);
    }

    return api_success($deviceGroup->id, 'id', 'Device group ' . $deviceGroup->name . ' created', 201);
}

function update_device_group(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    $name = $request->route('name');
    if (! $name) {
        return api_error(400, 'No device group name provided');
    }

    $deviceGroup = ctype_digit($name) ? DeviceGroup::find($name) : DeviceGroup::where('name', $name)->first();

    if (! $deviceGroup) {
        return api_error(404, "Device group $name not found");
    }

    $rules = [
        'name' => 'sometimes|string|unique:device_groups',
        'desc' => 'sometimes|string',
        'type' => 'sometimes|in:dynamic,static',
        'devices' => 'array|required_if:type,static',
        'devices.*' => 'integer',
        'rules' => 'json|required_if:type,dynamic',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return api_error(422, $v->messages());
    }

    if (! empty($data['rules'])) {
        // Only use the rules if they are able to be parsed by the QueryBuilder
        $query = QueryBuilderParser::fromJson($data['rules'])->toSql();
        if (empty($query)) {
            return api_error(500, "We couldn't parse your rule");
        }
    }

    $validatedData = $v->safe()->only(['name', 'desc', 'type']);
    $deviceGroup->fill($validatedData);

    if ($deviceGroup->type == 'static' && array_key_exists('devices', $data)) {
        $deviceGroup->devices()->sync($data['devices']);
    }

    if ($deviceGroup->type == 'dynamic' && ! empty($data['rules'])) {
        $deviceGroup->rules = json_decode($data['rules']);
    }

    try {
        $deviceGroup->save();
    } catch (\Illuminate\Database\QueryException $e) {
        return api_error(500, 'Failed to save changes device group');
    }

    return api_success_noresult(200, "Device group $name updated");
}

function delete_device_group(Illuminate\Http\Request $request)
{
    $name = $request->route('name');
    if (! $name) {
        return api_error(400, 'No device group name provided');
    }

    $deviceGroup = ctype_digit($name) ? DeviceGroup::find($name) : DeviceGroup::where('name', $name)->first();

    if (! $deviceGroup) {
        return api_error(404, "Device group $name not found");
    }

    $deleted = $deviceGroup->delete();

    if (! $deleted) {
        return api_error(500, "Device group $name could not be removed");
    }

    return api_success_noresult(200, "Device group $name deleted");
}

function update_device_group_add_devices(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    $name = $request->route('name');
    if (! $name) {
        return api_error(400, 'No device group name provided');
    }

    $deviceGroup = ctype_digit($name) ? DeviceGroup::find($name) : DeviceGroup::where('name', $name)->first();

    if (! $deviceGroup) {
        return api_error(404, "Device group $name not found");
    }

    if ('static' != $deviceGroup->type) {
        return api_error(422, 'Only static device group can have devices added');
    }

    $rules = [
        'devices' => 'array',
        'devices.*' => 'integer',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return api_error(422, $v->messages());
    }

    $deviceGroup->devices()->syncWithoutDetaching($data['devices']);

    return api_success_noresult(200, 'Devices added');
}

function update_device_group_remove_devices(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    $name = $request->route('name');
    if (! $name) {
        return api_error(400, 'No device group name provided');
    }

    $deviceGroup = ctype_digit($name) ? DeviceGroup::find($name) : DeviceGroup::where('name', $name)->first();

    if (! $deviceGroup) {
        return api_error(404, "Device group $name not found");
    }

    if ('static' != $deviceGroup->type) {
        return api_error(422, 'Only static device group can have devices added');
    }

    $rules = [
        'devices' => 'array',
        'devices.*' => 'integer',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return api_error(422, $v->messages());
    }

    $deviceGroup->devices()->detach($data['devices']);

    return api_success_noresult(200, 'Devices removed');
}

function get_device_groups(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');

    if ($hostname) {
        $device = ctype_digit($hostname) ? Device::find($hostname) : Device::findByHostname($hostname);
        if (is_null($device)) {
            return api_error(404, 'Device not found');
        }
        $query = $device->groups();
    } else {
        $query = DeviceGroup::query();
    }

    $groups = $query->hasAccess(Auth::user())->orderBy('name')->get();

    if ($groups->isEmpty()) {
        return api_error(404, 'No device groups found');
    }

    return api_success($groups->makeHidden('pivot')->toArray(), 'groups', 'Found ' . $groups->count() . ' device groups');
}

function maintenance_devicegroup(Illuminate\Http\Request $request)
{
    $data = $request->json()->all();

    if (empty($data)) {
        return api_error(400, 'No information has been provided to set this device into maintenance');
    }

    $name = $request->route('name');
    if (! $name) {
        return api_error(400, 'No device group name provided');
    }

    $device_group = ctype_digit($name) ? DeviceGroup::find($name) : DeviceGroup::where('name', $name)->first();

    if (! $device_group) {
        return api_error(404, "Device group $name not found");
    }

    if (empty($data['duration'])) {
        return api_error(400, 'Duration not provided');
    }

    $notes = $data['notes'] ?? '';
    $title = $data['title'] ?? $device_group->name;
    $behavior = MaintenanceBehavior::tryFrom((int) ($data['behavior'] ?? -1))
        ?? LibrenmsConfig::get('alert.scheduled_maintenance_default_behavior');

    $alert_schedule = new \App\Models\AlertSchedule([
        'title' => $title,
        'notes' => $notes,
        'behavior' => $behavior,
        'recurring' => 0,
    ]);

    $start = $data['start'] ?? \Carbon\Carbon::now()->format('Y-m-d H:i:00');
    $alert_schedule->start = $start;

    $duration = $data['duration'];

    if (Str::contains($duration, ':')) {
        [$duration_hour, $duration_min] = explode(':', $duration);
        $alert_schedule->end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $start)
            ->addHours((float) $duration_hour)->addMinutes((float) $duration_min)
            ->format('Y-m-d H:i:00');
    }

    $alert_schedule->save();
    $alert_schedule->deviceGroups()->attach($device_group);

    if (isset($data['start'])) {
        return api_success_noresult(201, "Device group {$device_group->name} ({$device_group->id}) will begin maintenance mode at $start" . ($duration ? " for {$duration}h" : ''));
    } else {
        return api_success_noresult(201, "Device group {$device_group->name} ({$device_group->id}) moved into maintenance mode" . ($duration ? " for {$duration}h" : ''));
    }
}

function get_devices_by_group(Illuminate\Http\Request $request)
{
    $name = $request->route('name');
    if (! $name) {
        return api_error(400, 'No device group name provided');
    }

    $device_group = ctype_digit($name) ? DeviceGroup::find($name) : DeviceGroup::where('name', $name)->first();

    if (empty($device_group)) {
        return api_error(404, 'Device group not found');
    }

    $devices = $device_group->devices()->get($request->get('full') ? ['*'] : ['devices.device_id']);

    if ($devices->isEmpty()) {
        return api_error(404, 'No devices found in group ' . $name);
    }

    return api_success($devices->makeHidden('pivot')->toArray(), 'devices');
}

function list_vrf(Illuminate\Http\Request $request)
{
    $sql = '';
    $sql_params = [];
    $hostname = $request->get('hostname');
    $vrfname = $request->get('vrfname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    if (is_numeric($device_id)) {
        $permission = check_device_permission($device_id);
        if ($permission !== true) {
            return $permission;
        }
        $sql = ' AND `devices`.`device_id`=?';
        $sql_params = [$device_id];
    }
    if (! empty($vrfname)) {
        $sql = '  AND `vrfs`.`vrf_name`=?';
        $sql_params = [$vrfname];
    }
    if (! Auth::user()->hasGlobalRead()) {
        $sql .= ' AND `vrfs`.`device_id` IN (SELECT device_id FROM devices_perms WHERE user_id = ?)';
        $sql_params[] = Auth::id();
    }

    $vrfs = dbFetchRows("SELECT `vrfs`.* FROM `vrfs` LEFT JOIN `devices` ON `vrfs`.`device_id` = `devices`.`device_id` WHERE `vrfs`.`vrf_name` IS NOT NULL $sql", $sql_params);
    $total_vrfs = count($vrfs);
    if ($total_vrfs == 0) {
        return api_error(404, 'VRFs do not exist');
    }

    return api_success($vrfs, 'vrfs');
}

function get_vrf(Illuminate\Http\Request $request)
{
    $vrfId = $request->route('id');
    if (! is_numeric($vrfId)) {
        return api_error(400, 'Invalid id has been provided');
    }

    $vrf = dbFetchRows('SELECT * FROM `vrfs` WHERE `vrf_id` IS NOT NULL AND `vrf_id` = ?', [$vrfId]);
    $vrf_count = count($vrf);
    if ($vrf_count == 0) {
        return api_error(404, "VRF $vrfId does not exist");
    }

    return api_success($vrf, 'vrf');
}

function list_mpls_services(Illuminate\Http\Request $request)
{
    $hostname = $request->get('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    $mpls_services = MplsService::hasAccess(Auth::user())->when($device_id, function ($query, $device_id) {
        return $query->where('device_id', $device_id);
    })->get();

    if ($mpls_services->isEmpty()) {
        return api_error(404, 'MPLS Services do not exist');
    }

    return api_success($mpls_services, 'mpls_services', null, 200, $mpls_services->count());
}

function list_mpls_saps(Illuminate\Http\Request $request)
{
    $hostname = $request->get('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    $mpls_saps = MplsSap::hasAccess(Auth::user())->when($device_id, function ($query, $device_id) {
        return $query->where('device_id', $device_id);
    })->get();

    if ($mpls_saps->isEmpty()) {
        return api_error(404, 'SAPs do not exist');
    }

    return api_success($mpls_saps, 'saps', null, 200, $mpls_saps->count());
}

function list_ipsec(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    // use hostname as device_id if it's all digits
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    if (! is_numeric($device_id)) {
        return api_error(400, 'No valid hostname or device ID provided');
    }

    $ipsec = dbFetchRows('SELECT `D`.`hostname`, `I`.* FROM `ipsec_tunnels` AS `I`, `devices` AS `D` WHERE `I`.`device_id`=? AND `D`.`device_id` = `I`.`device_id`', [$device_id]);

    return api_success($ipsec, 'ipsec');
}

function list_vlans(Illuminate\Http\Request $request)
{
    $sql = '';
    $sql_params = [];
    $hostname = $request->get('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    if (is_numeric($device_id)) {
        $permission = check_device_permission($device_id);
        if ($permission !== true) {
            return $permission;
        }
        $sql = ' AND `devices`.`device_id` = ?';
        $sql_params[] = $device_id;
    }
    if (! Auth::user()->hasGlobalRead()) {
        $sql .= ' AND `vlans`.`device_id` IN (SELECT device_id FROM devices_perms WHERE user_id = ?)';
        $sql_params[] = Auth::id();
    }

    $vlans = dbFetchRows("SELECT `vlans`.* FROM `vlans` LEFT JOIN `devices` ON `vlans`.`device_id` = `devices`.`device_id` WHERE `vlans`.`vlan_vlan` IS NOT NULL $sql", $sql_params);
    $vlans_count = count($vlans);
    if ($vlans_count == 0) {
        return api_error(404, 'VLANs do not exist');
    }

    return api_success($vlans, 'vlans');
}

function list_links(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $sql = '';
    $sql_params = [];

    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    if (is_numeric($device_id)) {
        $permission = check_device_permission($device_id);
        if ($permission !== true) {
            return $permission;
        }
        $sql = ' AND `links`.`local_device_id`=?';
        $sql_params = [$device_id];
    }
    if (! Auth::user()->hasGlobalRead()) {
        $sql .= ' AND `links`.`local_device_id` IN (SELECT device_id FROM devices_perms WHERE user_id = ?)';
        $sql_params[] = Auth::id();
    }
    $links = dbFetchRows("SELECT `links`.* FROM `links` LEFT JOIN `devices` ON `links`.`local_device_id` = `devices`.`device_id` WHERE `links`.`id` IS NOT NULL $sql", $sql_params);
    $total_links = count($links);
    if ($total_links == 0) {
        return api_error(404, 'Links do not exist');
    }

    return api_success($links, 'links');
}

function get_link(Illuminate\Http\Request $request)
{
    $linkId = $request->route('id');
    if (! is_numeric($linkId)) {
        return api_error(400, 'Invalid id has been provided');
    }

    $link = dbFetchRows('SELECT * FROM `links` WHERE `id` IS NOT NULL AND `id` = ?', [$linkId]);
    $link_count = count($link);
    if ($link_count == 0) {
        return api_error(404, "Link $linkId does not exist");
    }

    return api_success($link, 'link');
}

function get_fdb(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(500, 'No hostname has been provided');
    }

    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $device = null;
    if ($device_id) {
        // save the current details for returning to the client on successful delete
        $device = Device::find($device_id);
    }

    if (! $device) {
        return api_error(404, "Device $hostname not found");
    }

    return check_device_permission($device_id, function () use ($device) {
        if ($device) {
            $fdb = $device->portsFdb;

            return api_success($fdb, 'ports_fdb');
        }

        return api_error(404, 'Device does not exist');
    });
}

function get_nac(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(500, 'No hostname has been provided');
    }

    $device = \App\Facades\DeviceCache::get($hostname);
    if (! $device->exists) {
        return api_error(404, "Device $hostname not found");
    }

    return check_device_permission($device_id, function () use ($device) {
        $nac = $device->portsNac;

        return api_success($nac, 'ports_nac');
    });
}

function get_transceivers(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');

    if (empty($hostname)) {
        return api_error(500, 'No hostname has been provided');
    }

    $device = DeviceCache::get($hostname);

    if (! $device) {
        return api_error(404, "Device $hostname not found");
    }

    return api_success($device->transceivers()->hasAccess($request->user())->get(), 'transceivers');
}

function list_fdb(Illuminate\Http\Request $request)
{
    $mac = $request->route('mac');

    $fdb = PortsFdb::hasAccess(Auth::user())
           ->when(! empty($mac), function (Builder $query) use ($mac) {
               return $query->where('mac_address', $mac);
           })
           ->get();

    if ($fdb->isEmpty()) {
        return api_error(404, 'Fdb entry does not exist');
    }

    return api_success($fdb, 'ports_fdb');
}

function list_fdb_detail(Illuminate\Http\Request $request)
{
    $macAddress = Mac::parse($request->route('mac'));

    if (! $macAddress->isValid()) {
        return api_error(422, 'Invalid MAC address');
    }

    $extras = ['mac' => $macAddress->readable(),  'mac_oui' => $macAddress->vendor()];

    $fdb = PortsFdb::hasAccess(Auth::user())
        ->leftJoin('ports', 'ports_fdb.port_id', 'ports.port_id')
        ->leftJoin('devices', 'ports_fdb.device_id', 'devices.device_id')
        ->where('mac_address', $macAddress->hex())
        ->orderBy('ports_fdb.updated_at', 'desc')
        ->select('devices.hostname', 'devices.sysName', 'ports.ifName', 'ports.ifAlias', 'ports.ifDescr', 'ports_fdb.updated_at')
        ->limit(1000)->get();

    if ($fdb->isEmpty()) {
        return api_error(404, 'Fdb entry does not exist');
    }

    foreach ($fdb as $i => $fdb_entry) {
        if ($fdb_entry['updated_at']) {
            $fdb[$i]['last_seen'] = $fdb_entry['updated_at']->diffForHumans();
            $fdb[$i]['updated_at'] = $fdb_entry['updated_at']->toDateTimeString();
        }
    }

    return api_success($fdb, 'ports_fdb', null, 200, count($fdb), $extras);
}

function list_nac(Illuminate\Http\Request $request)
{
    $mac = $request->route('mac');

    $nac = PortsNac::hasAccess(Auth::user())
           ->when(! empty($mac), function (Builder $query) use ($mac) {
               return $query->where('mac_address', $mac);
           })
           ->get();

    if ($nac->isEmpty()) {
        return api_error(404, ' Nac entry does not exist');
    }

    return api_success($nac, 'ports_nac');
}

function list_sensors()
{
    $sensors = Sensor::hasAccess(Auth::user())->get();
    $total_sensors = $sensors->count();
    if ($total_sensors == 0) {
        return api_error(404, 'Sensors do not exist');
    }

    return api_success($sensors, 'sensors');
}

function list_ip_addresses(Illuminate\Http\Request $request)
{
    $address_family = $request->route('address_family');

    if ($address_family === 'ipv4') {
        $ipv4_addresses = Ipv4Address::get();
        if ($ipv4_addresses->isEmpty()) {
            return api_error(404, 'IPv4 Addresses do not exist');
        }

        return api_success($ipv4_addresses, 'ip_addresses', null, 200, $ipv4_addresses->count());
    }

    if ($address_family === 'ipv6') {
        $ipv6_addresses = Ipv6Address::get();
        if ($ipv6_addresses->isEmpty()) {
            return api_error(404, 'IPv6 Addresses do not exist');
        }

        return api_success($ipv6_addresses, 'ip_addresses', null, 200, $ipv6_addresses->count());
    }

    if (empty($address_family)) {
        $ipv4_addresses = Ipv4Address::get()->toArray();
        $ipv6_addresses = Ipv6Address::get()->toArray();
        $ip_addresses_count = count(array_merge($ipv4_addresses, $ipv6_addresses));
        if ($ip_addresses_count == 0) {
            return api_error(404, 'IP addresses do not exist');
        }

        return api_success(array_merge($ipv4_addresses, $ipv6_addresses), 'ip_addresses', null, 200, $ip_addresses_count);
    }
}

function list_ip_networks(Illuminate\Http\Request $request)
{
    $address_family = $request->route('address_family');

    if ($address_family === 'ipv4') {
        $ipv4_networks = Ipv4Network::get();
        if ($ipv4_networks->isEmpty()) {
            return api_error(404, 'IPv4 Networks do not exist');
        }

        return api_success($ipv4_networks, 'ip_networks', null, 200, $ipv4_networks->count());
    }
    if ($address_family === 'ipv6') {
        $ipv6_networks = Ipv6Network::get();
        if ($ipv6_networks->isEmpty()) {
            return api_error(404, 'IPv6 Networks do not exist');
        }

        return api_success($ipv6_networks, 'ip_networks', null, 200, $ipv6_networks->count());
    }
    if (empty($address_family)) {
        $ipv4_networks = Ipv4Network::get()->toArray();
        $ipv6_networks = Ipv6Network::get()->toArray();
        $ip_networks_count = count(array_merge($ipv4_networks, $ipv6_networks));
        if ($ip_networks_count == 0) {
            return api_error(404, 'IP networks do not exist');
        }

        return api_success(array_merge($ipv4_networks, $ipv6_networks), 'ip_networks', null, 200, $ip_networks_count);
    }
}

function list_arp(Illuminate\Http\Request $request)
{
    $query = $request->route('query');
    $cidr = $request->route('cidr');
    $hostname = $request->get('device');

    if (empty($query)) {
        return api_error(400, 'No valid IP/MAC provided');
    }

    if ($query === 'all') {
        $arp = $request->has('device')
            ? \DeviceCache::get($hostname)->macs
            : Ipv4Mac::all();
    } elseif ($cidr) {
        try {
            $ip = new IPv4("$query/$cidr");
            $arp = Ipv4Mac::whereRaw('(inet_aton(`ipv4_address`) & ?) = ?', [ip2long($ip->getNetmask()), ip2long($ip->getNetworkAddress())])->get();
        } catch (InvalidIpException $e) {
            return api_error(400, 'Invalid Network Address');
        }
    } elseif (filter_var($query, FILTER_VALIDATE_MAC)) {
        $mac = Mac::parse($query)->hex();
        $arp = Ipv4Mac::where('mac_address', $mac)->get();
    } else {
        $arp = Ipv4Mac::where('ipv4_address', $query)->get();
    }

    return api_success($arp, 'arp');
}

function list_services(Illuminate\Http\Request $request)
{
    $where = [];
    $params = [];

    // Filter by State
    if ($request->has('state')) {
        $where[] = '`service_status`=?';
        $params[] = $request->get('state');
        $where[] = "`service_disabled`='0'";
        $where[] = "`service_ignore`='0'";

        if (! is_numeric($request->get('state'))) {
            return api_error(400, 'No valid service state provided, valid option is 0=Ok, 1=Warning, 2=Critical');
        }
    }

    //Filter by Type
    if ($request->has('type')) {
        $where[] = '`service_type` LIKE ?';
        $params[] = $request->get('type');
    }

    //GET by Host
    $hostname = $request->route('hostname');
    if ($hostname) {
        $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
        $where[] = '`device_id` = ?';
        $params[] = $device_id;

        if (! is_numeric($device_id)) {
            return api_error(500, 'No valid hostname or device id provided');
        }
    }

    $query = 'SELECT * FROM `services`';

    if (! empty($where)) {
        $query .= ' WHERE ' . implode(' AND ', $where);
    }
    $query .= ' ORDER BY `service_ip`';
    $services = [dbFetchRows($query, $params)]; // double array for backwards compat :(

    return api_success($services, 'services');
}

function list_logs(Illuminate\Http\Request $request, Router $router)
{
    $type = $router->current()->getName();
    $hostname = $request->route('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);

    $count_query = 'SELECT COUNT(*)';
    $param = [];
    if ($type === 'list_eventlog') {
        $query = ' FROM eventlog LEFT JOIN `devices` ON `eventlog`.`device_id`=`devices`.`device_id` WHERE 1';
        $full_query = 'SELECT `devices`.`hostname`, `devices`.`sysName`, `eventlog`.`device_id` as `host`, `eventlog`.*'; // inject host for backward compat
        $timestamp = 'datetime';
        $id_field = 'event_id';
    } elseif ($type === 'list_syslog') {
        $query = ' FROM syslog LEFT JOIN `devices` ON `syslog`.`device_id`=`devices`.`device_id` WHERE 1';
        $full_query = 'SELECT `devices`.`hostname`, `devices`.`sysName`, `syslog`.*';
        $timestamp = 'timestamp';
        $id_field = 'seq';
    } elseif ($type === 'list_alertlog') {
        $query = ' FROM alert_log LEFT JOIN `devices` ON `alert_log`.`device_id`=`devices`.`device_id` WHERE 1';
        $full_query = 'SELECT `devices`.`hostname`, `devices`.`sysName`, `alert_log`.*';
        $timestamp = 'time_logged';
        $id_field = 'id';
    } elseif ($type === 'list_authlog') {
        $query = ' FROM authlog WHERE 1';
        $full_query = 'SELECT `authlog`.*';
        $timestamp = 'datetime';
        $id_field = 'id';
    } else {
        $query = ' FROM eventlog LEFT JOIN `devices` ON `eventlog`.`device_id`=`devices`.`device_id` WHERE 1';
        $full_query = 'SELECT `devices`.`hostname`, `devices`.`sysName`, `eventlog`.*';
        $timestamp = 'datetime';
    }

    $start = (int) $request->get('start', 0);
    $limit = (int) $request->get('limit', 50);
    $from = $request->get('from');
    $to = $request->get('to');

    if (is_numeric($device_id)) {
        $query .= ' AND `devices`.`device_id` = ?';
        $param[] = $device_id;
    }

    if ($from) {
        if (is_numeric($from)) {
            $query .= " AND $id_field >= ?";
        } else {
            $query .= " AND $timestamp >= ?";
        }
        $param[] = $from;
    }

    if ($to) {
        if (is_numeric($to)) {
            $query .= " AND $id_field <= ?";
        } else {
            $query .= " AND $timestamp <= ?";
        }
        $param[] = $to;
    }

    $sort_order = $request->get('sortorder') === 'DESC' ? 'DESC' : 'ASC';

    $count_query = $count_query . $query;
    $count = dbFetchCell($count_query, $param);
    $full_query = $full_query . $query . " ORDER BY $timestamp $sort_order LIMIT $start,$limit";
    $logs = dbFetchRows($full_query, $param);

    if ($type === 'list_alertlog') {
        foreach ($logs as $index => $log) {
            $logs[$index]['details'] = json_decode(gzuncompress($log['details']), true);
        }
    }

    return api_success($logs, 'logs', null, 200, null, ['total' => $count]);
}

/**
 * @throws \LibreNMS\Exceptions\ApiException
 */
function validate_column_list(?string $columns, string $table, array $default = []): array
{
    if ($columns == '') { // no user input, return default
        return $default;
    }

    static $schema;
    if (is_null($schema)) {
        $schema = new \LibreNMS\DB\Schema();
    }

    $column_names = is_array($columns) ? $columns : explode(',', $columns);
    $valid_columns = $schema->getColumns($table);
    $invalid_columns = array_diff(array_map('trim', $column_names), $valid_columns);

    if (count($invalid_columns) > 0) {
        throw new InvalidTableColumnException($invalid_columns);
    }

    return $column_names;
}

function missing_fields($required_fields, $data)
{
    foreach ($required_fields as $required) {
        if (empty($data[$required])) {
            return true;
        }
    }

    return false;
}

function add_service_template_for_device_group(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (json_last_error() || ! is_array($data)) {
        return api_error(400, "We couldn't parse the provided json. " . json_last_error_msg());
    }

    $rules = [
        'name' => 'required|string|unique:service_templates',
        'device_group_id' => 'integer',
        'type' => 'string',
        'param' => 'nullable|string',
        'ip' => 'nullable|string',
        'desc' => 'nullable|string',
        'changed' => 'integer',
        'disabled' => 'integer',
        'ignore' => 'integer',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return api_error(422, $v->messages());
    }

    // Only use the rules if they are able to be parsed by the QueryBuilder
    $query = QueryBuilderParser::fromJson($data['rules'])->toSql();
    if (empty($query)) {
        return api_error(500, "We couldn't parse your rule");
    }

    $serviceTemplate = ServiceTemplate::make(['name' => $data['name'], 'device_group_id' => $data['device_group_id'], 'type' => $data['type'], 'param' => $data['param'], 'ip' => $data['ip'], 'desc' => $data['desc'], 'changed' => $data['changed'], 'disabled' => $data['disabled'], 'ignore' => $data['ignore']]);
    $serviceTemplate->save();

    return api_success($serviceTemplate->id, 'id', 'Service Template ' . $serviceTemplate->name . ' created', 201);
}

function get_service_templates(Illuminate\Http\Request $request)
{
    if ($request->user()->cannot('viewAny', ServiceTemplate::class)) {
        return api_error(403, 'Insufficient permissions to access service templates');
    }

    $templates = ServiceTemplate::query()->orderBy('name')->get();

    if ($templates->isEmpty()) {
        return api_error(404, 'No service templates found');
    }

    return api_success($templates->makeHidden('pivot')->toArray(), 'templates', 'Found ' . $templates->count() . ' service templates');
}

function add_service_for_host(Illuminate\Http\Request $request)
{
    $hostname = $request->route('hostname');
    $device_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
    $data = json_decode($request->getContent(), true);
    if (missing_fields(['type'], $data)) {
        return api_error(400, 'Required fields missing (hostname and type needed)');
    }
    if (! in_array($data['type'], list_available_services())) {
        return api_error(400, 'The service ' . $data['type'] . " does not exist.\n Available service types: " . implode(', ', list_available_services()));
    }
    $service_type = $data['type'];
    $service_ip = $data['ip'];
    $service_desc = $data['desc'] ? $data['desc'] : '';
    $service_param = $data['param'] ? $data['param'] : '';
    $service_ignore = $data['ignore'] ? true : false; // Default false
    $service_disable = $data['disable'] ? true : false; // Default false
    $service_name = $data['name'];
    $service_id = add_service($device_id, $service_type, $service_desc, $service_ip, $service_param, (int) $service_ignore, (int) $service_disable, 0, $service_name);
    if ($service_id != false) {
        return api_success_noresult(201, "Service $service_type has been added to device $hostname (#$service_id)");
    }

    return api_error(500, 'Failed to add the service');
}

function add_parents_to_host(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    $device_id = $request->route('id');
    $device_id = ctype_digit($device_id) ? $device_id : getidbyname($device_id);

    $parent_ids = [];
    foreach (explode(',', $data['parent_ids']) as $hostname) {
        $hostname = trim($hostname);
        $parent_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
        if (empty($parent_id)) {
            return api_error(400, 'Parent device IDs/Hostname does not exist: ' . $hostname);
        }
        $parent_ids[] = $parent_id;
    }

    if (validateDeviceIds($parent_ids) && validateDeviceIds([$device_id]) && (! in_array($device_id, $parent_ids))) {
        Device::find($device_id)->parents()->sync($parent_ids);

        return api_success_noresult(201, 'Device dependencies have been saved');
    }

    return api_error(400, 'Check your parent and device IDs');
}

function del_parents_from_host(Illuminate\Http\Request $request)
{
    $device_id = $request->route('id');
    $device_id = ctype_digit($device_id) ? $device_id : getidbyname($device_id);
    $data = json_decode($request->getContent(), true);
    if (! validateDeviceIds([$device_id])) {
        return api_error(400, 'Check your device ID!');
    }
    $device = Device::find($device_id);
    if (! empty($data['parent_ids'])) {
        foreach (explode(',', $data['parent_ids']) as $hostname) {
            $hostname = trim($hostname);
            $parent_id = ctype_digit($hostname) ? $hostname : getidbyname($hostname);
            if (empty($parent_id)) {
                return api_error(400, 'Parent device IDs/Hostname does not exist: ' . $hostname);
            }
            $parent_ids[] = $parent_id;
        }

        //remove parents included in the request if they are valid device ids
        $result = validateDeviceIds($parent_ids) ? $device->parents()->detach($parent_ids) : false;
    }
    if (is_null($result)) {
        //$result doesn't exist so $data['parent_ids'] is empty
        $result = $device->parents()->detach(); //remove all parents
    }
    if ($result) {
        return api_success_noresult(201, 'All device dependencies have been removed');
    }

    return api_error(400, 'Device dependency cannot be deleted check device and parents ids');
}

function validateDeviceIds($ids)
{
    foreach ($ids as $id) {
        $invalidId = ! is_numeric($id) || $id < 1 || is_null(Device::find($id));
        if ($invalidId) {
            return false;
        }
    }

    return true;
}

function add_location(Illuminate\Http\Request $request)
{
    $data = json_decode($request->getContent(), true);
    if (missing_fields(['location', 'lat', 'lng'], $data)) {
        return api_error(400, 'Required fields missing (location, lat and lng needed)');
    }
    // Set the location
    $location = new \App\Models\Location($data);
    $location->fixed_coordinates = $data['fixed_coordinates'] ?? $location->coordinatesValid();

    if ($location->save()) {
        return api_success_noresult(201, "Location added with id #$location->id");
    }

    return api_error(500, 'Failed to add the location');
}

function edit_location(Illuminate\Http\Request $request)
{
    $location = $request->route('location_id_or_name');
    if (empty($location)) {
        return api_error(400, 'No location has been provided to edit');
    }
    $location_id = ctype_digit($location) ? $location : get_location_id_by_name($location);
    $data = json_decode($request->getContent(), true);
    if (empty($location_id)) {
        return api_error(400, 'Failed to delete location');
    }
    $result = dbUpdate($data, 'locations', '`id` = ?', [$location_id]);
    if ($result == 1) {
        return api_success_noresult(201, 'Location updated successfully');
    }

    return api_error(500, 'Failed to update location');
}

function get_location(Illuminate\Http\Request $request)
{
    $location = $request->route('location_id_or_name');
    if (empty($location)) {
        return api_error(400, 'No location has been provided to get');
    }
    $data = ctype_digit($location) ? Location::find($location) : Location::where('location', $location)->first();
    if (empty($data)) {
        return api_error(404, 'Location does not exist');
    }

    return api_success($data, 'get_location');
}

function get_location_id_by_name($location)
{
    return dbFetchCell('SELECT id FROM locations WHERE location = ?', $location);
}

function del_location(Illuminate\Http\Request $request)
{
    $location = $request->route('location');
    if (empty($location)) {
        return api_error(400, 'No location has been provided to delete');
    }
    $location_id = ctype_digit($location) ? $location : get_location_id_by_name($location);
    if (empty($location_id)) {
        return api_error(400, "Failed to delete $location (Does not exists)");
    }
    $data = [
        'location_id' => 0,
    ];
    dbUpdate($data, 'devices', '`location_id` = ?', [$location_id]);
    $result = dbDelete('locations', '`id` = ? ', [$location_id]);
    if ($result == 1) {
        return api_success_noresult(201, "Location $location has been deleted successfully");
    }

    return api_error(500, "Failed to delete the location $location");
}

function get_poller_group(Illuminate\Http\Request $request)
{
    $poller_group = $request->route('poller_group_id_or_name');
    if (empty($poller_group)) {
        return api_success(PollerGroup::get(), 'get_poller_group');
    }

    $data = ctype_digit($poller_group) ? PollerGroup::find($poller_group) : PollerGroup::where('group_name', $poller_group)->first();
    if (empty($data)) {
        return api_error(404, 'Poller Group does not exist');
    }

    return api_success($data, 'get_poller_group');
}

function del_service_from_host(Illuminate\Http\Request $request)
{
    $service_id = $request->route('id');
    if (empty($service_id)) {
        return api_error(400, 'No service_id has been provided to delete');
    }
    $result = delete_service($service_id);
    if ($result == 1) {
        return api_success_noresult(201, 'Service has been deleted successfully');
    }

    return api_error(500, 'Failed to delete the service');
}

function search_by_mac(Illuminate\Http\Request $request)
{
    $macAddress = Mac::parse((string) $request->route('search'))->hex();

    $rules = [
        'macAddress' => 'required|string|regex:/^[0-9a-fA-F]{12}$/',
    ];

    $validate = Validator::make(['macAddress' => $macAddress], $rules);
    if ($validate->fails()) {
        return api_error(422, $validate->messages());
    }

    $ports = Port::whereHas('fdbEntries', function ($fdbDownlink) use ($macAddress) {
        $fdbDownlink->where('mac_address', $macAddress);
    })
         ->withCount('fdbEntries')
         ->orderBy('fdb_entries_count')
         ->get();

    if ($ports->count() == 0) {
        return api_error(404, 'mac not found');
    }

    if ($request->has('filter') && $request->get('filter') === 'first') {
        return  api_success($ports->first(), 'ports');
    }

    return api_success($ports, 'ports');
}
function edit_service_for_host(Illuminate\Http\Request $request)
{
    $service_id = $request->route('id');
    $data = json_decode($request->getContent(), true);
    if (edit_service($data, $service_id) == 1) {
        return api_success_noresult(201, 'Service updated successfully');
    }

    return api_error(500, "Failed to update the service with id $service_id");
}

/**
 * recieve syslog messages via json https://github.com/librenms/librenms/pull/14424
 */
function post_syslogsink(Illuminate\Http\Request $request)
{
    $json = $request->json()->all();

    if (is_null($json)) {
        return api_success_noresult(400, 'Not valid json');
    }

    $logs = array_is_list($json) ? $json : [$json];

    foreach ($logs as $entry) {
        process_syslog($entry, 1);
    }

    return api_success_noresult(200, 'Syslog received: ' . count($logs));
}

/**
 * Display Librenms Instance Info
 */
function server_info()
{
    $version = \LibreNMS\Util\Version::get();

    $versions = [
        'local_ver' => $version->name(),
        'local_sha' => $version->git->commitHash(),
        'local_date' => $version->date(),
        'local_branch' => $version->git->branch(),
        'db_schema' => $version->database(),
        'php_ver' => phpversion(),
        'python_ver' => $version->python(),
        'database_ver' => $version->databaseServer(),
        'rrdtool_ver' => $version->rrdtool(),
        'netsnmp_ver' => $version->netSnmp(),
    ];

    return api_success([
        $versions,
    ], 'system');
}
