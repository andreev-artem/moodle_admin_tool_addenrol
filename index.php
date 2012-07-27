<?php

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

define('FAKE_DOMAIN', '@fake.mail');

// user params
$username       = optional_param('username', '', PARAM_NOTAGS);
$password       = optional_param('newpassword', '', PARAM_RAW);
$firstname      = optional_param('firstname', '', PARAM_TEXT);
$lastname       = optional_param('lastname', '', PARAM_TEXT);
$email          = optional_param('email', '', PARAM_NOTAGS);
$city           = optional_param('city', '', PARAM_TEXT);

// enrol params
$allcourses     = optional_param('allcourses', '', PARAM_CLEAN);
$selcourses     = optional_param('selcourses', '', PARAM_CLEAN);
$accept         = optional_param('accept', 0, PARAM_BOOL);
$confirm        = optional_param('confirm', 0, PARAM_BOOL);
$cancel         = optional_param('cancel', 0, PARAM_BOOL);
$searchtext     = optional_param('searchtext', '', PARAM_RAW);
$groups         = optional_param('groups', '', PARAM_CLEAN);
$roleassign     = optional_param('roleassign', '', PARAM_RAW);
$showall        = optional_param('showall', 0, PARAM_BOOL);
$listadd        = optional_param('add', 0, PARAM_BOOL);
$listremove     = optional_param('remove', 0, PARAM_BOOL);
$removeall      = optional_param('removeall', 0, PARAM_BOOL);
$hidden         = optional_param('hidden', 0, PARAM_BOOL);

admin_externalpage_setup('tooladdenrol');

$return = $CFG->wwwroot . '/' . $CFG->admin . '/tool/addenrol/index.php';
$pluginname = 'tool_addenrol';

if ($showall) {
    $searchtext = '';
}

$strsearch = get_string('search');

if (!isset($SESSION->bulk_courses) || $removeall) {
    $SESSION->bulk_courses = array();
}

// course selection add/remove actions
if ($listadd && !empty($allcourses)) {
    foreach ($allcourses as $course) {
        if (!in_array($course, $SESSION->bulk_courses)) {
            $SESSION->bulk_courses[] = $course;
        }
    }
}

if ($listremove && !empty($selcourses)) {
    foreach ($selcourses as $course) {
        unset($SESSION->bulk_courses[array_search($course, $SESSION->bulk_courses)]);
    }
}

if ($accept) {
    require_once($CFG->dirroot . '/group/lib.php');

    if (empty($SESSION->bulk_courses)) {
        redirect($return);
    }

    // create user
    $user = new object();
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->username = $username;
    
    $user->username = $username;
    $user->password = hash_internal_user_password($password);
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;
    if (strcmp(substr($email, strlen($email) - strlen(FAKE_DOMAIN)),FAKE_DOMAIN)===0) {
        $user->emailstop = 1;
    }
    $user->city = $city;
    $user->country = $CFG->country;
    $user->lang = $CFG->lang;

    global $DB;

    $existinguser = $DB->get_record('user', array('username' => addslashes($user->username), 'mnethostid' => $user->mnethostid));
    if ($existinguser) {
        print_error('loginexists', $pluginname, $return);
    }

    $user->confirmed = 1;
    $user->timemodified = time();

    $user->id = $DB->insert_record('user', $user);
    if (!$user->id) {
        print_error('erroradd', $pluginname, $return);
    }

    // make sure user context exists
    get_context_instance(CONTEXT_USER, $user->id);

    // create helper for logging
    $courseslist = get_courses("all", 'c.sortorder ASC', 'c.id, c.fullname');
    $courses = array();
    foreach ($courseslist as $course) {
        $courses[$course->id] = $course->fullname;
    }

    // for logging
    $enrolstrings = array();
    $problemcourseids = array();
    foreach ($SESSION->bulk_courses as $courseid) {
        $groupids = array();
        foreach ($groups as $group) {
            $groupid = groups_get_group_by_name($courseid, stripslashes($group));
            if ($groupid) {
                $groupids[$groupid] = $group;
            }
        }

        if ($roleassign == 0) {
            if ($enrol = enrol_get_plugin('manual'))
                $roleassign = $enrol->get_config('roleid');
            else {
                $problemcourseids[] = $courseid;
                continue;
            }
        }

        $existinggroups = array();
        if (enrol_try_internal_enrol($courseid, $user->id, $roleassign, time())) {
            foreach ($groupids as $groupid => $groupname) {
                try {
                    groups_add_member($groupid, $user->id);
                    $existinggroups[] = $groupname;
                } catch(Exception $e) {

                }
            }
        }
        else $problemcourseids[] = $courseid;

        // for logging
        $groupsstr = join(', ', $existinggroups);
        $enrolstrings[] = $courses[$courseid] . ($groupsstr ? ' (' . $groupsstr . ')' : '');
    }
    $enrolstr = join('; ', $enrolstrings);

    $desctext = get_string('logadding', $pluginname, $user->firstname . ' ' . $user->lastname . ' (' . $user->username .')');
    // get system roles info and add the selected role to the message
    if ($roleassign != 0) {
        $role = $DB->get_record('role', array('id' => $roleassign));
        $rolename = $role->name;
    } else {
        $rolename = get_string('default', $pluginname, NULL, $langdir);
    }
    $desctext .= ' ' . get_string('logrole', $pluginname, $rolename);
    $desctext .= ' ' . get_string('logcourses', $pluginname, $enrolstr);
    if (count($problemcourseids)) $desctext .= ' '.get_string('nointernalenrol', $pluginname, implode(', ', $problemcourseids));
    add_to_log($COURSE->id, 'user', 'add and enrol', 'view.php?id='.$user->id.'&course=1', $desctext, '', $USER->id);

    if (count($problemcourseids)) {
        global $OUTPUT;

        echo $OUTPUT->header();
        html_writer::tag('p', get_string('nointernalenrol', $pluginname, implode(', ', $problemcourseids)));
        echo $OUTPUT->continue_button($return);
        echo $OUTPUT->footer();
        die;
    }

    redirect($return, get_string('changessaved'));
}

/**
 * This function generates the list of courses for <select> control
 * using the specified string filter and/or course id's filter
 *
 * @param string $strfilter The course name filter
 * @param array $arrayfilter Course ID's filter, NULL by default, which means not to use id filter
 * @return string
 */
function gen_course_list($strfilter = '', $arrayfilter = NULL, $filtinvert = false) {
    $courselist = array();
    $catcnt = 0;
    // get the list of course categories
    $categories = get_categories();
    foreach ($categories as $cat) {
        // for each category, add the <optgroup> to the string array first
        $courselist[$catcnt] = '<optgroup label="' . htmlspecialchars($cat->name) . '">';
        // get the course list in that category
        $courses = get_courses($cat->id, 'c.sortorder ASC', 'c.fullname, c.id');
        $coursecnt = 0;

        // for each course, check the specified filter
        foreach ($courses as $course) {
            if ((!empty($strfilter) && strripos($course->fullname, $strfilter) === false ) || ( $arrayfilter !== NULL && in_array($course->id, $arrayfilter) === $filtinvert )) {
                continue;
            }
            // if we pass the filter, add the option to the current string
            $courselist[$catcnt] .= '<option value="' . $course->id . '">' . $course->fullname . '</option>';
            $coursecnt++;
        }

        // if no courses pass the filter in that category, delete the current string
        if ($coursecnt == 0) {
            unset($courselist[$catcnt]);
        } else {
            $courselist[$catcnt] .= '</optgroup>';
            $catcnt++;
        }
    }

    // return the html code with categorized courses
    return implode(' ', $courselist);
}

// generate full and selected course lists
$coursenames = gen_course_list($searchtext, $SESSION->bulk_courses, true);
$selcoursenames = gen_course_list('', $SESSION->bulk_courses);

// generate the list of groups names from the selected courses.
// groups with the same name appear only once
$groupnames = array();
foreach ($SESSION->bulk_courses as $course) {
    $cgroups = groups_get_all_groups($course);
    if ($cgroups) {
        foreach ($cgroups as $cgroup) {
            if (!in_array($cgroup->name, $groupnames)) {
                $groupnames[] = $cgroup->name;
            }
        }
    }
}

sort($groupnames);

// generate html code for the group select control
foreach ($groupnames as $key => $name) {
    $groupnames[$key] = '<option value="' . s($name, true) . '" >' . s($name, true) . '</option>';
}

$groupnames = implode(' ', $groupnames);

$courseroles = get_roles_for_contextlevels(CONTEXT_COURSE);
$context = get_context_instance(CONTEXT_SYSTEM);
list($courseviewroles, $ignored) = get_roles_with_cap_in_context($context, 'moodle/course:view');
$enrolableroles = array_diff_key(array_combine($courseroles, $courseroles), $courseviewroles);
$roles = array_intersect_key(get_all_roles(), $enrolableroles);
$roles[0] = (object) array('name' => get_string('default', $pluginname));

$rolenames = '';
foreach ($roles as $key => $role) {
    $rolenames .= '<option value="' . $key . '"';
    if ($key == $roleassign) {
        $rolenames .= ' selected ';
    }
    $rolenames .= '>' . $role->name . '</option> ';
}

global $OUTPUT;

echo $OUTPUT->header();
?>
<div id="addmembersform">
    <h3 class="main"><?php echo get_string('title', $pluginname) ?></h3>

    <form id="addform" method="post" action="index.php">
        <table cellpadding="6" class="generaltable generalbox groupmanagementtable boxaligncenter">
            <tr>
                <td align="right">
                    <label for="id_username"><?php echo get_string('username') ?></label>
                </td>
                <td>
                    <input type="text" id="id_username" name="username" size="20"
                           onchange="
                               var email_input = document.getElementById('id_email');
                               if (email_input.value == '') {
                                   email_input.value = 'tmp'+document.getElementById('id_username').value+'<?php echo FAKE_DOMAIN ?>';
                               }
                           " <?php if ($username !== '') echo 'value="' . $username . '"'?>
                           />
                </td>
            </tr>
            <tr>
                <td align="right">
                    <label for="id_newpassword"><?php echo get_string('newpassword') ?></label>
                </td>
                <td>
                    <input type="password" id="id_newpassword" name="newpassword" size="20" autocomplete="off"
                           <?php if ($password !== '') echo 'value="' . $password . '"'?>
                           />
                    <div class="unmask"><input type="checkbox" onclick="unmaskPassword('id_newpassword')" value="1" id="id_newpasswordunmask"><label for="id_newpasswordunmask"><?php echo get_string('revealpassword', 'form') ?></label></div>
                </td>
            </tr>
            <tr>
                <td align="right">
                    <label for="id_firstname"><?php echo get_string('firstname') ?></label>
                </td>
                <td>
                    <input type="text" id="id_firstname" name="firstname" size="30" maxlength="100"
                           <?php if ($firstname !== '') echo 'value="' . $firstname . '"'?>
                           />
                </td>
            </tr>
            <tr>
                <td align="right">
                    <label for="id_lastname"><?php echo get_string('lastname') ?></label>
                </td>
                <td>
                    <input type="text" id="id_lastname" name="lastname" size="30" maxlength="100"
                           <?php if ($lastname !== '') echo 'value="' . $lastname . '"'?>
                           />
                </td>
            </tr>
            <tr>
                <td align="right">
                    <label for="id_email"><?php echo get_string('email') ?></label>
                </td>
                <td>
                    <input type="text" id="id_email" name="email" size="30" maxlength="100"
                           <?php if ($email !== '') echo 'value="' . $email . '"'?>
                           />
                </td>
            </tr>
            <tr>
                <td align="right">
                    <label for="id_city"><?php echo get_string('city') ?></label>
                </td>
                <td>
                    <input type="text" id="id_city" name="city" size="30" maxlength="100"
                           <?php if ($city !== '') echo 'value="' . $city . '"'?>
                           />
                </td>
            </tr>
        </table>
        <table cellpadding="6" class="selectcourses generaltable generalbox boxaligncenter" summary="">
            <tr>
              <td id="existingcell">
                    <p>
                        <label for="allcourses"><?php echo get_string('allcourses', $pluginname) ?></label>
                    </p>
                    <select name="allcourses[]" size="20" id="allcourses" multiple="multiple"
                            onfocus="document.getElementById('addform').add.disabled=false;
                                document.getElementById('addform').remove.disabled=true;
                                document.getElementById('addform').selcourses.selectedIndex=-1;"
                            onclick="this.focus();">
                                <?php echo $coursenames ?>
                    </select>

                    <br />
                    <label for="searchtext" class="accesshide"><?php p($strsearch) ?></label>
                    <input type="text" name="searchtext" id="searchtext" size="21" value="<?php p($searchtext, true) ?>"
                           onfocus ="getElementById('addform').add.disabled=true;
                               getElementById('addform').remove.disabled=true;
                               getElementById('addform').allcourses.selectedIndex=-1;
                               getElementById('addform').selcourses.selectedIndex=-1;"
                           onkeydown = "var keyCode = event.which ? event.which : event.keyCode;
                               if (keyCode == 13) {
                                   getElementById('addform').previoussearch.value=1;
                                   getElementById('addform').submit();
                               } " />
                    <input name="search" id="search" type="submit" value="<?php p($strsearch) ?>" />
                    <?php
                        if (!empty($searchtext)) {
                            echo '<br /><input name="showall" id="showall" type="submit" value="' . get_string('showall') . '" />' . "\n";
                        }
                    ?>
                </td>
              <td id="buttonscell">
                  <div id="addcontrols">
                        <input name="add" id="add" type="submit" disabled value="<?php echo '&nbsp;' . $OUTPUT->rarrow() . ' &nbsp; &nbsp; ' . get_string('add'); ?>" title="<?php print_string('add'); ?>" />
                  </div>
                  <div id="removecontrols">
                        <input name="remove" id="remove" type="submit" disabled value="<?php echo '&nbsp; ' . $OUTPUT->larrow() . ' &nbsp; &nbsp; ' . get_string('remove'); ?>" title="<?php print_string('remove'); ?>" />
                  </div>
                 </td>
          <td id="potentialcell">
                     <p>
                         <label for="selcourses"><?php echo get_string('selectedcourses', $pluginname) ?></label>
                     </p>
                     <select name="selcourses[]" size="20" id="selcourses" multiple="multiple"
                             onfocus="document.getElementById('addform').remove.disabled=false;
                                      document.getElementById('addform').add.disabled=true;
                                      document.getElementById('addform').allcourses.selectedIndex=-1;"
                             onclick="this.focus();">
                            <?php echo $selcoursenames; ?>
                    </select>
                    <br />
                    <input name="removeall" id="removeall" type="submit" value="<?php echo get_string('removeall', 'bulkusers') ?>" />
                </td>
                </tr>
                <tr>
                    <td align="center">
                        <label for="roleassign"><?php echo get_string('roletoset', $pluginname) ?></label>
                        <br />
                        <select name="roleassign" id="roleassign" size="1">
                        <?php echo $rolenames ?>
                                </select>
                    </td>
                    <td align="center">
                        <label for="groups"><?php echo get_string('autogroup', $pluginname) ?></label>
                        <br />
                        <select name="groups[]" id="groups" size="10" multiple="multiple"
                                onchange="
                                    var groups = document.getElementById('groups');
                                    var selectedgroups = new Array;
                                    for (var i=0; i < groups.options.length; i++)
                                    {
                                        if (groups.options[i].selected)
                                            selectedgroups.push('<nobr>' + groups.options[i].value + '</nobr>');
                                    }
                                    document.getElementById('selectedgroups').innerHTML = selectedgroups.join(', ');
                                "
                                >
                            <?php echo $groupnames; ?>
                        </select>
                    </td>
                    <td>
                        <br /><br />
                        <div id="selectedgroups" style="width:200px"></div>
                    </td>
                </tr>
                <tr><td></td><td align="center">
                        <p><input type="submit" name="cancel" value="<?php echo get_string('cancel') ?>" />
                            <input type="submit" name="accept" value="<?php echo get_string('accept', $pluginname) ?>" /></p>
                    </td>
                </tr>

        </table>
    </form>
</div>
<?php
echo $OUTPUT->footer();
?>
