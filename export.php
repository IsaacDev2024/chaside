<?php
// This file is part of Moodle - http://moodle.org/

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);
$currentgroup = optional_param('group', 0, PARAM_INT);

$result = block_chaside_get_completed_responses($courseid, $currentgroup);

if ($result === false) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
}

list($course, $responses) = $result;
$context = context_course::instance($courseid);

if (empty($responses)) {
    redirect(new moodle_url('/blocks/chaside/admin_view.php', array('courseid' => $courseid)), 
             get_string('no_responses_yet', 'block_chaside'), null, 'error');
}

$facade = new \block_chaside\facade();
$export_data = array();

foreach ($responses as $response) {
    // Convert stdClass to array for the facade
    $response_array = (array) $response;
    
    try {
        $scores = $facade->calculate_scores($response_array);
        $detailed_scores = $facade->calculate_detailed_scores($response_array);
        $top_areas = $facade->get_top_areas_v2($detailed_scores, 3);
    } catch (Exception $e) {
        // If calculation fails, use empty arrays
        $scores = array('C' => '', 'H' => '', 'A' => '', 'S' => '', 'I' => '', 'D' => '', 'E' => '');
        $detailed_scores = array('C' => array('interes_score' => '', 'aptitud_score' => ''), 'H' => array('interes_score' => '', 'aptitud_score' => ''), 'A' => array('interes_score' => '', 'aptitud_score' => ''), 'S' => array('interes_score' => '', 'aptitud_score' => ''), 'I' => array('interes_score' => '', 'aptitud_score' => ''), 'D' => array('interes_score' => '', 'aptitud_score' => ''), 'E' => array('interes_score' => '', 'aptitud_score' => ''));
        $top_areas = array();
    }
    
    // Safely access score keys with fallback
    $get_score = function($key) use ($scores) {
        return isset($scores[$key]) ? $scores[$key] : '';
    };
    
    $get_detailed = function($area, $type) use ($detailed_scores) {
        return isset($detailed_scores[$area][$type]) ? $detailed_scores[$area][$type] : '';
    };
    
    $export_data[] = array(
        'student_id' => $response->idnumber,
        'student_name' => $response->firstname . ' ' . $response->lastname,
        'student_email' => $response->email,
        'completion_date' => date('Y-m-d H:i:s', $response->timemodified),
        'administrative_score' => $get_score('C'),
        'administrative_interests' => $get_detailed('C', 'interes_score'),
        'administrative_aptitudes' => $get_detailed('C', 'aptitud_score'),
        'humanities_score' => $get_score('H'),
        'humanities_interests' => $get_detailed('H', 'interes_score'),
        'humanities_aptitudes' => $get_detailed('H', 'aptitud_score'),
        'artistic_score' => $get_score('A'),
        'artistic_interests' => $get_detailed('A', 'interes_score'),
        'artistic_aptitudes' => $get_detailed('A', 'aptitud_score'),
        'health_sciences_score' => $get_score('S'),
        'health_sciences_interests' => $get_detailed('S', 'interes_score'),
        'health_sciences_aptitudes' => $get_detailed('S', 'aptitud_score'),
        'technical_score' => $get_score('I'),
        'technical_interests' => $get_detailed('I', 'interes_score'),
        'technical_aptitudes' => $get_detailed('I', 'aptitud_score'),
        'defense_security_score' => $get_score('D'),
        'defense_security_interests' => $get_detailed('D', 'interes_score'),
        'defense_security_aptitudes' => $get_detailed('D', 'aptitud_score'),
        'experimental_sciences_score' => $get_score('E'),
        'experimental_sciences_interests' => $get_detailed('E', 'interes_score'),
        'experimental_sciences_aptitudes' => $get_detailed('E', 'aptitud_score'),
        'top_area_1' => isset($top_areas[0]) ? $top_areas[0]['area'] : '',
        'top_area_2' => isset($top_areas[1]) ? $top_areas[1]['area'] : '',
        'top_area_3' => isset($top_areas[2]) ? $top_areas[2]['area'] : ''
    );
}

// Generate elegant filename using language string
$course_name = preg_replace('/[^a-z0-9]/i', '_', strtolower($course->shortname));
$date_str = date('Y-m-d');
$filename = get_string('export_filename', 'block_chaside') . '_' . $course_name . '_' . $date_str;

if ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $fp = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add translated headers
    $headers = array(
        get_string('export_student_id', 'block_chaside'),
        get_string('export_student_name', 'block_chaside'),
        get_string('export_student_email', 'block_chaside'),
        get_string('export_completion_date', 'block_chaside'),
        get_string('export_administrative_score', 'block_chaside'),
        get_string('export_administrative_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_administrative_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_humanities_score', 'block_chaside'),
        get_string('export_humanities_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_humanities_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_artistic_score', 'block_chaside'),
        get_string('export_artistic_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_artistic_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_health_sciences_score', 'block_chaside'),
        get_string('export_health_sciences_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_health_sciences_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_technical_score', 'block_chaside'),
        get_string('export_technical_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_technical_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_defense_security_score', 'block_chaside'),
        get_string('export_defense_security_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_defense_security_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_experimental_sciences_score', 'block_chaside'),
        get_string('export_experimental_sciences_score', 'block_chaside') . ' - ' . get_string('interests', 'block_chaside'),
        get_string('export_experimental_sciences_score', 'block_chaside') . ' - ' . get_string('aptitudes', 'block_chaside'),
        get_string('export_top_area', 'block_chaside') . ' 1',
        get_string('export_top_area', 'block_chaside') . ' 2', 
        get_string('export_top_area', 'block_chaside') . ' 3'
    );
    fputcsv($fp, $headers);
    
    // Add data
    foreach ($export_data as $row) {
        fputcsv($fp, $row);
    }
    
    fclose($fp);
    exit;
    
} elseif ($format == 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
    
} else {
    print_error('invalidformat', 'block_chaside');
}
