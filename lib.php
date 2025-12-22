<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Helper function to get completed responses for a course.
 *
 * @param int $courseid The course ID.
 * @param int $groupid Optional group ID.
 * @return array|false Array containing [$course, $responses] or false if permission denied/error.
 */
function block_chaside_get_completed_responses($courseid, $groupid = 0) {
    global $DB;
    
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    $context = context_course::instance($course->id);
    
    require_login($course, false);
    
    // Check permissions
    if (!has_capability('block/chaside:viewreports', $context) && !has_capability('block/chaside:manage_responses', $context) && !is_siteadmin()) {
        return false;
    }
    
    $student_ids = block_chaside_get_student_ids($context, $groupid);
    
    $responses = array();
    if (!empty($student_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED, 'user');
        $params['completed'] = 1;
        
        $responses = $DB->get_records_sql("
            SELECT cr.*, u.firstname, u.lastname, u.email, u.idnumber
            FROM {block_chaside_responses} cr
            JOIN {user} u ON cr.userid = u.id
            WHERE cr.userid $insql AND cr.is_completed = :completed
            ORDER BY cr.timemodified DESC
        ", $params);
    }
    
    return [$course, $responses];
}

/**
 * Helper function to get student IDs for a course context.
 *
 * @param context $context The course context.
 * @param int $groupid Optional group ID.
 * @return array Array of student user IDs.
 */
function block_chaside_get_student_ids($context, $groupid = 0) {
    // Get enrolled students
    $enrolled_users = get_enrolled_users($context, 'block/chaside:take_test', $groupid, 'u.id');
    $enrolled_ids = array_keys($enrolled_users);

    // Defensive: exclude any teacher/manager-type user even if misconfigured.
    $student_ids = array();
    foreach ($enrolled_ids as $candidateid) {
        $candidateid = (int)$candidateid;
        if (is_siteadmin($candidateid)) {
            continue;
        }
        if (has_capability('block/chaside:viewreports', $context, $candidateid) || has_capability('block/chaside:manage_responses', $context, $candidateid)) {
            continue;
        }
        $student_ids[] = $candidateid;
    }
    return $student_ids;
}
