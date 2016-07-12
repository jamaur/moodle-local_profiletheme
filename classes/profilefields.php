<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local plugin "Profile field based theme delivery" - Base class for handling settings based on user profile fields
 *
 * @package   local_profiletheme
 * @copyright 2016 Davo Smith, Synergy Learning UK on behalf of Alexander Bias, University of Ulm <alexander.bias@uni-ulm.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_profiletheme;

use moodleform;
use single_select;

defined('MOODLE_INTERNAL') || die();

/**
 * Class profilefields
 * @package local_profiletheme
 */
abstract class profilefields {
    protected static $tablename = null;
    /** @var field_base[] */
    protected $rules = null;
    /** @var string[] */
    protected $possiblefields = null;
    /** @var array */
    protected $possiblevalues = null;
    /** @var moodleform */
    protected $form = null;
    /** @var string */
    protected $action = 'view';

    protected static $actions = ['view', 'add'];

    /**
     * profilefields constructor.
     */
    public function __construct() {
        global $PAGE;

        if (!static::$tablename) {
            throw new \coding_exception('Must set $tablename in derived classes');
        }
        $this->action = optional_param('action', null, PARAM_ALPHA);
        if (!in_array($this->action, static::$actions)) {
            $this->action = 'view';
        }
        $url = new \moodle_url($PAGE->url, ['action' => $this->action]);
        $PAGE->set_url($url);
    }

    // ------------------------------------------
    // Admin form for editing mappings
    // ------------------------------------------

    /**
     * Process the submitted rule editing form.
     */
    public function process_form() {
        global $DB, $PAGE;

        $rules = [];
        if ($this->action == 'view') {
            $rules = $this->get_rules();
            if (!$rules) {
                $this->action = 'add';
            } else {
                $i = 1;
                foreach ($rules as $rule) {
                    $rule->set_form_position($i++);
                }
            }
        }

        $addid = null;
        if ($this->action == 'add') {
            // Add a new, empty, rule to the end of the list, if requested.
            if ($addid = optional_param('add', null, PARAM_INT)) {
                $field = $DB->get_record('user_info_field', array('id' => $addid), 'id AS fieldid, name, datatype, param1', MUST_EXIST);
                if ($rule = field_base::make_instance($field)) {
                    $rules[] = $rule;
                }
            }
        }

        // Instantiate the form.
        $custom = [
            'rules' => $rules,
            'values' => $this->get_possible_values()
        ];
        $this->form = new fields_form(null, $custom);
        $toform = ['action' => $this->action];
        if ($addid) {
            $toform['add'] = $addid;
        }
        $this->form->set_data($toform);

        // Process the form data.
        if ($this->form->is_cancelled()) {
            redirect($PAGE->url);
        }
        if ($formdata = $this->form->get_data()) {
            $changed = $this->figure_out_sortorder($rules, $formdata);
            foreach ($rules as $idx => $rule) {
                $changed = $rule->update_from_form_data(static::$tablename, $formdata) || $changed;
            }
            if ($changed) {
                $this->apply_all_rules();
            }
            // Always return to the 'view rules' tab when a rule has been saved successfully.
            redirect(new \moodle_url($PAGE->url, ['action' => 'view']));
        }
    }

    /**
     * Look to see if any of the rules have moved up or down, then rewrite the sort order, as needed.
     * New sortorder is stored in the $formdata, to be applied by $rule->update_from_form_data()
     *
     * @param field_base[] $rules
     * @param $formdata
     * @return bool true if there were any changes made
     */
    protected function figure_out_sortorder($rules, $formdata) {
        // Get list of rules that have moved up / down / stayed put.
        $positions = range(1, count($rules));
        $unchanged = array_fill_keys($positions, []);
        $movedup = array_fill_keys($positions, []);
        $moveddown = array_fill_keys($positions, []);

        $changed = false;
        foreach ($rules as $rule) {
            list($dir, $position) = $rule->get_new_position($formdata);
            if ($dir == 0) {
                $unchanged[$position][] = $rule;
            } else if ($dir < 0) {
                $movedup[$position][] = $rule;
                $changed = true;
            } else {
                $moveddown[$position][] = $rule;
                $changed = true;
            }
        }
        if (!$changed) {
            return false;
        }

        $sortorder = 1;
        $formdata->sortorder = [];
        for ($i = 1; $i <= count($rules); $i++) {
            // If there is more than one entry in any given position, order them by:
            // those that have moved up, then those that are unchanged, then those that have moved down.
            foreach ($movedup[$i] as $rule) {
                $formdata->sortorder[$rule->id] = $sortorder++;
            }
            foreach ($unchanged[$i] as $rule) {
                $formdata->sortorder[$rule->id] = $sortorder++;
            }
            foreach ($moveddown[$i] as $rule) {
                $formdata->sortorder[$rule->id] = $sortorder++;
            }
        }
        return true;
    }

    /**
     * Output the complete form for editing profile field mapping rules.
     * @return string
     */
    public function output_form() {
        global $OUTPUT;
        $out = '';

        if (!$this->get_possible_fields()) {
            return get_string('nofields', 'local_profiletheme');
        }

        $tabs = $this->get_tabs();
        $out .= $OUTPUT->render($tabs);

        if ($this->action == 'add') {
            $out .= $this->output_add_select();
        }
        $out .= $this->output_rules();
        return $out;
    }

    /**
     * Generate tabs for the display
     * @return \tabtree
     */
    protected function get_tabs() {
        global $PAGE;

        $tabs = [];
        $tabs[] = new \tabobject('view', new \moodle_url($PAGE->url, ['action' => 'view']),
                                 get_string('viewrules', 'local_profiletheme'));
        $tabs[] = new \tabobject('add', new \moodle_url($PAGE->url, ['action' => 'add']),
                                 get_string('addrules', 'local_profiletheme'));

        $tabtree = new \tabtree($tabs, $this->action);

        return $tabtree;
    }

    /**
     * Generate a drop-down select for adding a new profile field mapping rule.
     * @return string
     */
    protected function output_add_select() {
        global $OUTPUT, $PAGE;
        $opts = $this->get_possible_fields();
        $opts = array_map('format_string', $opts);
        $select = new single_select($PAGE->url, 'add', $opts, '', [null => get_string('addrule', 'local_profiletheme')]);
        $select->attributes['id'] = 'local_profiletheme_add';
        return $OUTPUT->render($select);
    }

    /**
     * Generate the form for editing profile field mapping rules.
     * @return string
     */
    protected function output_rules() {
        return $this->form->render();
    }

    /**
     * Apply the rules to all users on the site and update cohorts as required.
     */
    protected function apply_all_rules() {
        // Nothing to do in the base class.
    }

    // ------------------------------------------
    // Get the mapped value for a user
    // ------------------------------------------

    /**
     * For the given user, load their profile fields then match them against the
     * defined rules
     *
     * @param $userid
     * @param bool $matchall (optional) set to true to get an array of all matches
     *                        false (default) to get only the first match
     * @return array|null|string - array if $matchall is true, null (or empty array) if no match found
     */
    public static function get_mapped_value($userid, $matchall = false) {
        $ret = $matchall ? [] : null;

        if (!$rules = self::load_rules()) {
            return $ret;
        }
        $fields = self::load_profile_fields($rules, $userid);

        // Check the user profile fields against each of the rules.
        foreach ($rules as $rule) {
            if ($value = $rule->get_value($fields)) {
                if ($matchall) {
                    $ret[] = $value;
                } else {
                    return $value;
                }
            }
        }
        return $ret;
    }

    /**
     * Load all the profile fields that are used by the given rules
     *
     * @param field_base[] $rules
     * @param int[] $userid
     * @return string[] $fieldid => $fieldvalue
     */
    protected static function load_profile_fields($rules, $userid) {
        global $DB;

        $fieldids = [];
        foreach ($rules as $rule) {
            $fieldids[] = $rule->fieldid;
        }
        list($fsql, $params) = $DB->get_in_or_equal($fieldids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $select = "fieldid $fsql AND userid = :userid";
        return $DB->get_records_select_menu('user_info_data', $select, $params, '', 'fieldid, data');
    }

    // ------------------------------------------
    // Internal support functions
    // ------------------------------------------

    /**
     * Get the list of custom profile fields for which rules could be added.
     * @return string[] $fieldid => $fieldname
     */
    protected function get_possible_fields() {
        if ($this->possiblefields === null) {
            $this->possiblefields = self::load_possible_fields();
        }
        return $this->possiblefields;
    }

    /**
     * Load a list of custom profile fields for which rules could be added.
     * @return string[] $fieldid => $fieldname
     */
    protected static function load_possible_fields() {
        global $DB;
        $ret = [];
        $fields = $DB->get_records('user_info_field', [], 'name', 'id, name, datatype');
        foreach ($fields as $field) {
            if (field_base::make_instance($field, IGNORE_MISSING)) {
                $ret[$field->id] = $field->name;
            }
        }
        return $ret;
    }

    /**
     * Get a list of possible values that fields can be mapped onto.
     * @return string[] $value => $displayname
     */
    protected function get_possible_values() {
        if ($this->possiblevalues === null) {
            $this->possiblevalues = static::load_possible_values();
        }
        return $this->possiblevalues;
    }

    /**
     * Load a list of possible values that fields can be mapped onto.
     * @return string[] $value => $displayname
     */
    protected static function load_possible_values() {
        throw new \coding_exception('Must be overridden in the derived class');
    }

    /**
     * Get all the profile field rules for the site.
     * @return field_base[]
     */
    protected function get_rules() {
        if ($this->rules === null) {
            $this->rules = self::load_rules();
        }
        return $this->rules;
    }

    /**
     * Load all the profile field rules for the site.
     * @return field_base[]
     */
    protected static function load_rules() {
        global $DB;

        $rules = [];
        $tablename = static::$tablename;
        $sql = "SELECT  m.*, f.name, f.datatype, f.param1
                  FROM {{$tablename}} m
                  JOIN {user_info_field} f ON f.id = m.fieldid
                 ORDER BY m.sortorder";
        foreach ($DB->get_recordset_sql($sql) as $ruledata) {
            if ($rule = field_base::make_instance($ruledata, IGNORE_MISSING)) {
                $rules[] = $rule;
            }
        }

        static::remove_invalid_rules($rules);

        return $rules;
    }

    /**
     * Check the rules are valid.
     * @param field_base[] $rules
     */
    protected static function remove_invalid_rules(&$rules) {
        $possible = static::load_possible_values();
        foreach ($rules as $idx => $rule) {
            if (!array_key_exists($rule->value, $possible)) {
                // Remove the invalid rule from the list, but do not delete it (as it may become valid again later).
                unset($rules[$idx]);
            }
        }
    }
}
