<?php

// provide some sane default
if ($service['service_param']) {
    $params = $service['service_param'];
} else {
    $params = '-w 5,5,5 -c 10,10,10';
}

$check_cmd = \App\Facades\LibrenmsConfig::get('nagios_plugins') . '/check_load ' . $params;

if (isset($rrd_filename)) {
    // Check DS is a json array of the graphs that are available
    $check_ds = '{"load":""}';

    // Build the graph data
    $check_graph = [];
    $check_graph['load'] = ' DEF:DS0=' . $rrd_filename . ':load1:AVERAGE ';
    $check_graph['load'] .= ' LINE1.25:DS0#' . \App\Facades\LibrenmsConfig::get('graph_colours.mixed.0') . ":'" . str_pad(substr('Load 1', 0, 15), 15) . "' ";
    $check_graph['load'] .= ' GPRINT:DS0:LAST:%5.2lf%s ';
    $check_graph['load'] .= ' GPRINT:DS0:AVERAGE:%5.2lf%s ';
    $check_graph['load'] .= ' GPRINT:DS0:MAX:%5.2lf%s\\l ';
    $check_graph['load'] .= ' DEF:DS1=' . $rrd_filename . ':load5:AVERAGE ';
    $check_graph['load'] .= ' LINE1.25:DS1#' . \App\Facades\LibrenmsConfig::get('graph_colours.mixed.1') . ":'" . str_pad(substr('Load 5', 0, 15), 15) . "' ";
    $check_graph['load'] .= ' GPRINT:DS1:LAST:%5.2lf%s ';
    $check_graph['load'] .= ' GPRINT:DS1:AVERAGE:%5.2lf%s ';
    $check_graph['load'] .= ' GPRINT:DS1:MAX:%5.2lf%s\\l ';
    $check_graph['load'] .= ' DEF:DS2=' . $rrd_filename . ':load15:AVERAGE ';
    $check_graph['load'] .= ' LINE1.25:DS2#' . \App\Facades\LibrenmsConfig::get('graph_colours.mixed.2') . ":'" . str_pad(substr('Load 15', 0, 15), 15) . "' ";
    $check_graph['load'] .= ' GPRINT:DS2:LAST:%5.2lf%s ';
    $check_graph['load'] .= ' GPRINT:DS2:AVERAGE:%5.2lf%s ';
    $check_graph['load'] .= ' GPRINT:DS2:MAX:%5.2lf%s\\l ';
}
