<?php
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
if (!$courseid) {
    $courseid = required_param('cid', PARAM_INT);
}

if ($courseid == SITEID) {
    redirect($CFG->wwwroot);
}

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$PAGE->set_course($course);
$context = context_course::instance($courseid);
$PAGE->set_context($context);

require_login($course, false);

// Silent redirect for users without report capability (e.g., students)
if (!has_capability('block/chaside:viewreports', $context) && !is_siteadmin()) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
}

$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

// Process actions
if ($action === 'delete' && $userid && confirm_sesskey()) {
    $confirm = optional_param('confirm', 0, PARAM_INT);
    if ($confirm) {
        // Defensive: allow deletion only for users that are students in this course.
        $targetuser = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
        if (!is_siteadmin() && (
            !is_enrolled($context, $targetuser, 'block/chaside:take_test', true)
            || has_capability('block/chaside:viewreports', $context, $userid)
            || is_siteadmin($userid)
        )) {
            redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
        }
        // Delete user's test record
        $DB->delete_records('block_chaside_responses', array('userid' => $userid));
        redirect(new moodle_url('/blocks/chaside/admin_view.php', array('courseid' => $courseid)), 
                 get_string('response_deleted', 'block_chaside'));
    }
}

$PAGE->set_url('/blocks/chaside/admin_view.php', array('courseid' => $courseid));
$title = get_string('admin_dashboard', 'block_chaside');
$PAGE->set_pagelayout('standard');
$PAGE->set_title($title . " : " . $course->fullname);
$PAGE->set_heading($title . " : " . $course->fullname);

$PAGE->requires->css(new moodle_url('/blocks/chaside/styles.css'));

echo $OUTPUT->header();
echo "<div class='block_chaside_container'>";

// Generate chaside icon for header
$iconurl = new moodle_url('/blocks/chaside/pix/chaside_icon.svg');
echo "<h1 class='mb-4 text-center'>";
echo "<img src='" . $iconurl . "' alt='CHASIDE Icon' style='width: 50px; height: 50px; vertical-align: middle; margin-right: 15px;' />";
echo get_string('admin_dashboard', 'block_chaside');
echo "</h1>";

// Confirmation delete
if ($action === 'delete' && $userid) {
    $user = $DB->get_record('user', array('id' => $userid), 'firstname, lastname');
    if ($user) {
        echo "<div class='alert alert-warning'>";
        echo "<h4>" . get_string('confirm_delete', 'block_chaside') . "</h4>";
        echo "<p>" . get_string('deleteresponseconfirm', 'block_chaside', fullname($user)) . "</p>";
        echo "<div class='mt-3'>";
        echo "<a href='" . new moodle_url('/blocks/chaside/admin_view.php', 
                array('courseid' => $courseid, 'action' => 'delete', 'userid' => $userid, 'confirm' => 1, 'sesskey' => sesskey())) . 
                "' class='btn btn-danger '>" . get_string('confirm_delete_yes', 'block_chaside') . "</a> ";
        echo "<a href='" . new moodle_url('/blocks/chaside/admin_view.php', array('courseid' => $courseid)) . 
                "' class='btn btn-secondary text-white'>" . get_string('cancel') . "</a>";
        echo "</div>";
        echo "</div>";
    }
} else {
    // Description banner (match other admin dashboards)
    echo "<div class='alert alert-info mb-4'>";
    echo format_text(get_string('admin_dashboard_description', 'block_chaside'), FORMAT_HTML);
    echo "</div>";

    // Get statistics
    // Get enrolled students in this course
    $enrolled_students = get_enrolled_users($context, 'block/chaside:take_test', 0, 'u.id, u.firstname, u.lastname');
    $enrolled_ids = array_keys($enrolled_students);

    // Defensive: ensure only students show in admin tables (exclude report-capable users).
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
    $enrolled_ids = $student_ids;
    
    // Total students in course
    $total_enrolled = count($enrolled_ids);
    
    // Get participants with user information FIRST (before calculating statistics)
    $userfields = \core_user\fields::for_name()->with_userpic()->get_sql('u', false, '', '', false)->selects;
    $participants = array();
    if (!empty($enrolled_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($enrolled_ids, SQL_PARAMS_NAMED);
        $sql = "SELECT r.*, {$userfields}
                FROM {block_chaside_responses} r
                JOIN {user} u ON r.userid = u.id
                WHERE r.userid $insql
                ORDER BY r.timemodified DESC";
        
        $participants = $DB->get_records_sql($sql, $params);
    }
    
    // Count participants who are enrolled in this course
    $completed_tests = 0;
    $in_progress_tests = 0;
    if (!empty($enrolled_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($enrolled_ids, SQL_PARAMS_NAMED);
        
        // Count completed tests
        $params_completed = $params;
        $params_completed['completed'] = 1;
        $completed_tests = $DB->count_records_select('block_chaside_responses', "userid $insql AND is_completed = :completed", $params_completed);
        
        // Count in-progress tests
        $params_progress = $params;
        $params_progress['completed'] = 0;
        $in_progress_tests = $DB->count_records_select('block_chaside_responses', "userid $insql AND is_completed = :completed", $params_progress);
    }
    
    echo "<div class='row mb-4'>";
    
    // Total students card
    echo "<div class='col-md-3 mb-4'>";
    echo "<div class='card border-info' style='border-color: #ffb600 !important;'>";
    echo "<div class='card-body text-center'>";
    echo "<i class='fa fa-users text-primary' style='font-size: 2em; margin-bottom: 10px;'></i>";
    echo "<h5 class='card-title'>" . get_string('enrolled_students', 'block_chaside') . "</h5>";
    echo "<h2 class='text-primary'>" . $total_enrolled . "</h2>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Completed tests card
    echo "<div class='col-md-3 mb-4'>";
    echo "<div class='card border-success' style='border-color: #28a745 !important;'>";
    echo "<div class='card-body text-center'>";
    echo "<i class='fa fa-check-circle text-success' style='font-size: 2em; margin-bottom: 10px;'></i>";
    echo "<h5 class='card-title'>" . get_string('total_completed', 'block_chaside') . "</h5>";
    echo "<h2 class='text-success'>" . $completed_tests . "</h2>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // In progress tests card
    echo "<div class='col-md-3 mb-4'>";
    echo "<div class='card border-warning' style='border-color: #ffc107 !important;'>";
    echo "<div class='card-body text-center'>";
    echo "<i class='fa fa-hourglass-half text-warning' style='font-size: 2em; margin-bottom: 10px;'></i>";
    echo "<h5 class='card-title'>" . get_string('in_progress', 'block_chaside') . "</h5>";
    echo "<h2 class='text-warning'>" . $in_progress_tests . "</h2>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Completion rate card
    $completion_rate = $total_enrolled > 0 ? round(($completed_tests / $total_enrolled) * 100, 1) : 0;
    echo "<div class='col-md-3 mb-4'>";
    echo "<div class='card border-primary' style='border-color: #ffb600 !important;'>";   
    echo "<div class='card-body text-center'>"; 
    echo '<i class="fa fa-percent text-primary" style="font-size: 2em; margin-bottom: 10px;"></i>';
    echo "<h5 class='card-title'>" . get_string('completion_rate', 'block_chaside') . "</h5>";
    echo "<h2 class='text-primary'>" . $completion_rate . "%</h2>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    echo "</div>";

    // Keep a single source of truth for "completed" count used in stats sections.
    $completed_count = (int)$completed_tests;

    
    // General statistics section (only when there are completed tests).
    if ($completed_count > 0) {
        echo "<div class='row mt-4'>";
        echo "<div class='col-12'>";
        echo "<div class='card'>";
        echo "<div class='card-header'>";
        echo "<h5 class='mb-0'><i class='fa fa-chart-bar'></i> " . get_string('average_dimensions', 'block_chaside') . "</h5>";
        echo "</div>";
        echo "<div class='card-body'>";

        // Calculate average scores across ALL areas for completed tests.
        $area_totals = array('C' => 0, 'H' => 0, 'A' => 0, 'S' => 0, 'I' => 0, 'D' => 0, 'E' => 0);
        if (!empty($participants)) {
            $facade = new \block_chaside\facade();
            foreach ($participants as $p) {
                if ((int)$p->is_completed !== 1) {
                    continue;
                }
                $response_array = (array) $p;
                $scores = $facade->calculate_scores($response_array);
                foreach ($scores as $area => $score) {
                    if (!array_key_exists($area, $area_totals)) {
                        continue;
                    }
                    $area_totals[$area] += (float)$score;
                }
            }
        }

        echo "<div class='row'>";
        foreach ($area_totals as $area => $total) {
            $avg_score = round($total / $completed_count, 1);
            $area_name = get_string('area_' . strtolower($area), 'block_chaside');
            $preference_percentage = ($avg_score / 14) * 100;
            $progress_width = ($avg_score / 14) * 100;

            echo "<div class='col-lg-4 col-md-6 mb-3'>";
            echo "<div class='card chaside-area-avg-card h-100'>";
            echo "<div class='card-body'>";
            echo "<h6 class='mb-2'>" . $area . " - " . $area_name . "</h6>";
            echo "<div class='d-flex justify-content-between align-items-center mb-2'>";
            echo "<small class='text-muted'>" . get_string('average_score', 'block_chaside') . ":</small>";
            echo "<strong class='chaside-area-avg-score'>" . number_format($avg_score, 1) . "/14</strong>";
            echo "</div>";
            echo "<div class='progress mb-1'>";
            echo "<div class='progress-bar' style='width: " . $progress_width . "%;'></div>";
            echo "</div>";
            echo "<small class='text-muted'>" . number_format($preference_percentage, 1) . "% " . get_string('preference', 'block_chaside') . "</small>";
            echo "</div>";
            echo "</div>";
            echo "</div>";
        }
        echo "</div>"; // Close row statistics

        echo "</div>"; // Close card-body
        echo "</div>"; // Close card
        echo "</div>"; // Close col-12
        echo "</div>"; // Close row mt-4

        // Top Areas Statistics
        echo "<div class='row mt-4'>";
        echo "<div class='col-12'>";
        echo "<div class='card'>";
        echo "<div class='card-header'>";
        echo "<h5 class='mb-0'><i class='fa fa-trophy'></i> " . get_string('top_areas_distribution', 'block_chaside') . "</h5>";
        echo "</div>";
        echo "<div class='card-body'>";

    // Calculate Top Areas
    $top1_counts = array('C' => 0, 'H' => 0, 'A' => 0, 'S' => 0, 'I' => 0, 'D' => 0, 'E' => 0);
    $top2_counts = array('C' => 0, 'H' => 0, 'A' => 0, 'S' => 0, 'I' => 0, 'D' => 0, 'E' => 0);
    
    if (!empty($participants)) {
        $facade = new \block_chaside\facade();
        foreach ($participants as $p) {
            if ((int)$p->is_completed !== 1) continue;
            
            // Use detailed scores and official tie-breaker logic
            $detailed_scores = $facade->calculate_detailed_scores((array)$p);
            $top_areas = $facade->get_top_areas_v2($detailed_scores, 2);
            
            if (isset($top_areas[0])) {
                $top1_counts[$top_areas[0]['area']]++;
            }
            if (isset($top_areas[1])) {
                $top2_counts[$top_areas[1]['area']]++;
            }
        }
    }

    // Area Icons Mapping
    $area_icons = [
        'C' => 'fa-calculator',
        'H' => 'fa-book',
        'A' => 'fa-paint-brush',
        'S' => 'fa-user-md',
        'I' => 'fa-cogs',
        'D' => 'fa-shield',
        'E' => 'fa-flask'
    ];

    echo "<h6 class='mb-3'>" . get_string('primary_area_title', 'block_chaside') . "</h6>";
    echo "<div class='row'>";
    arsort($top1_counts); // Show most popular first
    foreach ($top1_counts as $area => $count) {
        if ($count == 0) continue;
        $pct = ($completed_count > 0) ? round(($count / $completed_count) * 100, 1) : 0;    
        $area_name = get_string('area_' . strtolower($area), 'block_chaside');
        $icon = isset($area_icons[$area]) ? $area_icons[$area] : 'fa-star';
        
        echo "<div class='col-lg-3 col-md-4 mb-3'>";
        echo "<div class='card h-100 shadow-sm' style='border-left: 4px solid #ffb600;'>";
        echo "<div class='card-body p-3'>";
        echo "<div class='d-flex align-items-center'>";
        echo "<div class='flex-shrink-0 mr-3'>";
        echo "<i class='fa {$icon} fa-2x' style='color: #ffb600;'></i>";
        echo "</div>";
        echo "<div class='flex-grow-1'>";
        echo "<h4 class='mb-0'>{$count} <small class='text-muted' style='font-size: 0.5em;'>({$pct}%)</small></h4>";
        echo "<div class='small text-muted font-weight-bold' style='line-height: 1.2;'>{$area} - {$area_name}</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    echo "</div>";

    echo "<hr>";

    echo "<h6 class='mb-3 mt-4'>" . get_string('secondary_area_title', 'block_chaside') . "</h6>";
    echo "<div class='row'>";
    arsort($top2_counts);
    foreach ($top2_counts as $area => $count) {
        if ($count == 0) continue;
        $pct = ($completed_count > 0) ? round(($count / $completed_count) * 100, 1) : 0;
        $area_name = get_string('area_' . strtolower($area), 'block_chaside');
        $icon = isset($area_icons[$area]) ? $area_icons[$area] : 'fa-star';
        
        echo "<div class='col-lg-3 col-md-4 mb-3'>";
        echo "<div class='card h-100 shadow-sm' style='border-left: 4px solid #6c757d;'>";
        echo "<div class='card-body p-3'>";
        echo "<div class='d-flex align-items-center'>";
        echo "<div class='flex-shrink-0 mr-3'>";
        echo "<i class='fa {$icon} fa-2x text-secondary'></i>";
        echo "</div>";
        echo "<div class='flex-grow-1'>";
        echo "<h4 class='mb-0'>{$count} <small class='text-muted' style='font-size: 0.5em;'>({$pct}%)</small></h4>";
        echo "<div class='small text-muted font-weight-bold' style='line-height: 1.2;'>{$area} - {$area_name}</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    echo "</div>";

        echo "</div>"; // Close card-body
        echo "</div>"; // Close card
        echo "</div>"; // Close col-12
        echo "</div>"; // Close row mt-4
    }

    // Participants List section
    if (empty($participants)) {
        echo "<div class='alert alert-info mt-4'>";
        echo "<i class='fa fa-info-circle'></i> ";
        echo "<h5>" . get_string('no_responses_found', 'block_chaside') . "</h5>";
        echo "<p>" . get_string('no_participants_message', 'block_chaside') . "</p>";
        echo "</div>";
    } else {
        echo "<div class='card mt-5'>";
        echo "<div class='card-header'>";
        echo "<h5 class='mb-0'>" . get_string('student_responses', 'block_chaside') . "</h5>";
        echo "</div>";
        echo "<div class='card-body'>";
        
        // Filters and search
        echo "<div class='row mb-3'>";
        echo "<div class='col-md-8'>";
        echo "<input type='text' id='searchInput' class='form-control' placeholder='" . s(get_string('search_student', 'block_chaside')) . "'>";
        echo "</div>";
        echo "<div class='col-md-4 text-end d-flex justify-content-center justify-content-md-start mt-3 mt-md-0'>";
        $download_csv_url = new moodle_url('/blocks/chaside/export.php', array('courseid' => $courseid, 'format' => 'csv'));
        echo "<button class='btn btn-success' onclick='exportData(\"csv\")'><i class='fa fa-download mr-2'></i>" . s(get_string('download_csv', 'block_chaside')) . "</button>";
        echo "</div>";
        echo "</div>";

        // Participants table
        echo "<div class='table-responsive'>";
        echo "<table class='table table-striped table-hover' id='participantsTable'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>" . get_string('student', 'block_chaside') . "</th>";
        echo "<th>" . get_string('email') . "</th>";
        echo "<th>" . get_string('completion_status', 'block_chaside') . "</th>";
        echo "<th>" . get_string('top_area', 'block_chaside') . "</th>";
        echo "<th>" . get_string('completiondate', 'block_chaside') . "</th>";
        echo "<th>" . get_string('actions', 'block_chaside') . "</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";

        $facade = new \block_chaside\facade();
        foreach ($participants as $participant) {
            echo "<tr class='participant-row'>";
            echo "<td>";
            echo "<div class='d-flex align-items-center'>";
            $userpicture = new user_picture($participant);
            $userpicture->size = 35;
            echo $OUTPUT->render($userpicture);
            echo "<span class='ms-2'><strong>" . fullname($participant) . "</strong></span>";
            echo "</div>";
            echo "</td>";
            echo "<td>" . $participant->email . "</td>";
            
            // Status and Progress
            echo "<td>";
            if ($participant->is_completed == 1) {
                echo "<span class='badge bg-success text-white'>" . get_string('completed_status', 'block_chaside') . "</span>";
            } else {
                // Calculate progress - count non-null responses (q1 to q98)
                $answered = 0;
                for ($i = 1; $i <= 98; $i++) {
                    $field = 'q' . $i;
                    if (isset($participant->$field) && $participant->$field !== null && $participant->$field !== '') {
                        $answered++;
                    }
                }
                echo "<span class='badge bg-warning text-dark'>" . get_string('in_progress_status', 'block_chaside') . "</span>";
                echo "<br><small class='text-muted'>" . $answered . "/98 " . get_string('questions', 'block_chaside') . "</small>";
            }
            echo "</td>";
            
            // Top Area (only if completed)
            echo "<td>";
            if ($participant->is_completed == 1) {
                $response_array = (array) $participant;
                
                // Use consistent tie-breaker logic
                $detailed_scores = $facade->calculate_detailed_scores($response_array);
                $top_areas = $facade->get_top_areas_v2($detailed_scores, 1);
                $top_area = isset($top_areas[0]) ? $top_areas[0]['area'] : '';
                
                if ($top_area) {
                    $area_name = get_string('area_' . strtolower($top_area), 'block_chaside');
                    echo "<strong>" . $top_area . " - " . $area_name . "</strong>";
                } else {
                    echo "<span class='text-muted'>-</span>";
                }
            } else {
                echo "<span class='text-muted'>-</span>";
            }
            echo "</td>";
            
            echo "<td>" . userdate($participant->timemodified, get_string('strftimedatetimeshort')) . "</td>";
            echo "<td>";
            echo "<a href='" . new moodle_url('/blocks/chaside/view_results.php', 
                    array('userid' => $participant->userid, 'courseid' => $courseid)) . 
                    "' class='btn btn-sm btn-info mr-2 mt-1 mb-1' title='" . get_string('viewresults', 'block_chaside') . "'>";
            echo "<i class='fa fa-eye'></i> " . get_string('view', 'block_chaside');
            echo "</a>";
            echo "<a href='" . new moodle_url('/blocks/chaside/admin_view.php', 
                    array('courseid' => $courseid, 'action' => 'delete', 'userid' => $participant->userid, 'sesskey' => sesskey())) . 
                    "' class='btn btn-sm btn-danger' title='" . get_string('deleteresponse', 'block_chaside') . "'>";
            echo "<i class='fa fa-trash'></i> " . get_string('delete');
            echo "</a>";
            echo "</td>";
            echo "</tr>";
        }

        echo "</tbody>";
        echo "</table>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }

    echo "</div>"; // Close block_chaside_container
}

// Back to course button
echo "<div class='mt-4 text-center'>";
echo "<a href='" . new moodle_url('/course/view.php', array('id' => $courseid)) . "' class='btn btn-secondary'>";
echo "<i class='fa fa-arrow-left'></i> " . get_string('back_to_course', 'block_chaside');
echo "</a>";
echo "</div>";

echo "</div>";

// JavaScript for functionality
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    
    function filterTable() {
        const filter = searchInput.value.toLowerCase();
        const rows = document.querySelectorAll('#participantsTable .participant-row');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const matchesSearch = text.includes(filter);
            row.style.display = matchesSearch ? '' : 'none';
        });
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', filterTable);
    }
    
    // Function to export data
    window.exportData = function(format) {
        if (format === 'csv') {
            window.location.href = '" . $CFG->wwwroot . "/blocks/chaside/export.php?courseid=" . $courseid . "&format=csv&sesskey=" . sesskey() . "';
        }
    };
});
</script>";

echo $OUTPUT->footer();
?>
