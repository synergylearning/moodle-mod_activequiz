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

namespace mod_activequiz\controllers;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/editlib.php');

/**
 * edit controller class to act as a controller for the edit page
 *
 * @package     mod_activequiz
 * @author      John Hoopes <hoopes@wisc.edu>
 * @copyright   2014 University of Wisconsin - Madison
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit {
    /** @var \mod_activequiz\activequiz Active quiz class */
    protected $RTQ;

    /** @var string $action The specified action to take */
    protected $action;

    /** @var object $context The specific context for this activity */
    protected $context;

    /** @var \question_edit_contexts $contexts and array of contexts that has all parent contexts from the RTQ context */
    protected $contexts;

    /** @var \moodle_url $pageurl The page url to base other calls on */
    protected $pageurl;

    /** @var array $this ->pagevars An array of page options for the page load */
    protected $pagevars;

    /**
     * Sets up the edit page
     *
     * @param string $baseurl the base url of the
     *
     * @return array Array of variables that the page is set up with
     */
    public function setup_page($baseurl) {
        global $PAGE, $CFG, $DB;

        $this->pagevars = array();

        $pageurl = new \moodle_url($baseurl);
        $pageurl->remove_all_params();

        $id = optional_param('id', false, PARAM_INT);
        $quizid = optional_param('quizid', false, PARAM_INT);

        // get necessary records from the DB
        if ($id) {
            $cm = get_coursemodule_from_id('activequiz', $id, 0, false, MUST_EXIST);
            $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
            $quiz = $DB->get_record('activequiz', array('id' => $cm->instance), '*', MUST_EXIST);
        } else {
            $quiz = $DB->get_record('activequiz', array('id' => $quizid), '*', MUST_EXIST);
            $course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('activequiz', $quiz->id, $course->id, false, MUST_EXIST);
        }
        $this->get_parameters(); // get the rest of the parameters and set them in the class

        if ($CFG->version < 2011120100) {
            $this->context = get_context_instance(CONTEXT_MODULE, $cm->id);
        } else {
            $this->context = \context_module::instance($cm->id);
        }


        //TODO: not sure this is really needed anymore as we are now using the question engine with its own capabilities
        //$this->RTQ->require_capability('mod/activequiz:editquestions');


        // set up question lib

        if (!empty($this->context)) {
            $this->contexts = new \question_edit_contexts($this->context);
            //$contexts->require_one_edit_tab_cap($edittab);
        } else {
            $this->contexts = null;
        }

        $this->pagevars['qpage'] = optional_param('qpage', -1, PARAM_INT);
        //pass 'cat' from page to page and when 'category' comes from a drop down menu
        //then we also reset the qpage so we go to page 1 of
        //a new cat.
        $this->pagevars['cat'] = optional_param('cat', 0, PARAM_SEQUENCE); // if empty will be set up later
        if ($category = optional_param('category', 0, PARAM_SEQUENCE)) {
            if ($this->pagevars['cat'] != $category) { // is this a move to a new category?
                $this->pagevars['cat'] = $category;
                $this->pagevars['qpage'] = 0;
            }
        }
        if ($this->pagevars['cat']) {
            $pageurl->param('cat', $this->pagevars['cat']);
        }
        if (strpos($baseurl, '/question/') === 0) {
            \navigation_node::override_active_url($pageurl);
        }

        if ($this->pagevars['qpage'] > -1) {
            $pageurl->param('qpage', $this->pagevars['qpage']);
        } else {
            $this->pagevars['qpage'] = 0;
        }

        $this->pagevars['qperpage'] = question_get_display_preference(
            'qperpage', DEFAULT_QUESTIONS_PER_PAGE, PARAM_INT, $pageurl);

        for ($i = 1; $i <= \question_bank_view::MAX_SORTS; $i++) {
            $param = 'qbs' . $i;
            if (!$sort = optional_param($param, '', PARAM_ALPHAEXT)) {
                break;
            }
            $pageurl->param($param, $sort);
        }

        $defaultcategory = question_make_default_categories($this->contexts->all());

        $contextlistarr = array();
        foreach ($this->contexts->having_one_edit_tab_cap('editq') as $context) {
            $contextlistarr[] = "'$context->id'";
        }
        $contextlist = join($contextlistarr, ' ,');
        if (!empty($this->pagevars['cat'])) {
            $catparts = explode(',', $this->pagevars['cat']);
            if (!$catparts[0] || (false !== array_search($catparts[1], $contextlistarr)) ||
                !$DB->count_records_select("question_categories", "id = ? AND contextid = ?", array($catparts[0], $catparts[1]))
            ) {
                print_error('invalidcategory', 'question');
            }
        } else {
            $category = $defaultcategory;
            $this->pagevars['cat'] = "$category->id,$category->contextid";
        }

        // Display options. //TODO: determine if we want to use these or just keep it simple for now
        /*$this->pagevars['recurse']    = question_get_display_preference('recurse',    1, PARAM_BOOL, $pageurl);
        $this->pagevars['showhidden'] = question_get_display_preference('showhidden', 0, PARAM_BOOL, $pageurl);
        $this->pagevars['qbshowtext'] = question_get_display_preference('qbshowtext', 0, PARAM_BOOL, $pageurl);*/


        // set up page information
        $pageurl->params(array('id' => $cm->id, 'quiz' => $quiz->id));
        $this->pagevars['pageurl'] = $pageurl;
        require_login($course->id, false, $cm);
        $PAGE->set_url($pageurl);
        $this->pageurl = $pageurl;
        $PAGE->set_pagelayout('incourse');

        $PAGE->set_title(strip_tags($course->shortname . ': ' . get_string("modulename", "activequiz")
            . ': ' . format_string($quiz->name, true)));
        $PAGE->set_heading($course->fullname);

        add_to_log($course->id, "activequiz", "edit: $this->action", "edit.php?quizid=$quizid");

        // setup classes needed for the edit page
        $this->RTQ = new \mod_activequiz\activequiz($cm, $course, $quiz, $this->pagevars);
        $this->RTQ->get_renderer()->init($this->RTQ, $this->pageurl, $this->pagevars);

    }

    /**
     * Handles the action specified
     *
     */
    public function handle_action() {
        global $PAGE, $DB;

        if($sessions = $DB->get_records('activequiz_sessions', array('activequizid' => $this->RTQ->getRTQ()->id, 'sessionopen'=> '1'))){
            $this->RTQ->get_renderer()->print_editpage_header();
            $this->RTQ->get_renderer()->editpage_opensession();
            $this->RTQ->get_renderer()->end_editpage();
            return; // return early to stop continuation.
        }

        switch ($this->action) {
            case 'moveup':

                $questionid = required_param('questionid', PARAM_INT);

                if ($this->RTQ->get_questionmanager()->move_question('up', $questionid)) {
                    $type = 'success';
                    $message = get_string('qmovesuccess', 'activequiz');
                } else {
                    $type = 'error';
                    $message = get_string('qmoveerror', 'activequiz');
                }

                $this->RTQ->get_renderer()->setMessage($type, $message);
                $this->RTQ->get_renderer()->print_editpage_header($this->RTQ);
                $this->list_questions();
                $this->RTQ->get_renderer()->end_editpage();

                break;
            case 'movedown':

                $questionid = required_param('questionid', PARAM_INT);

                if ($this->RTQ->get_questionmanager()->move_question('down', $questionid)) {
                    $type = 'success';
                    $message = get_string('qmovesuccess', 'activequiz');
                } else {
                    $type = 'error';
                    $message = get_string('qmoveerror', 'activequiz');
                }

                $this->RTQ->get_renderer()->setMessage($type, $message);
                $this->RTQ->get_renderer()->print_editpage_header($this->RTQ);
                $this->list_questions();
                $this->RTQ->get_renderer()->end_editpage();

                break;
            case 'addquestion':

                $questionid = required_param('questionid', PARAM_INT);
                $this->RTQ->get_questionmanager()->add_question($questionid);

                break;
            case 'editquestion':

                $questionid = required_param('rtqquestionid', PARAM_INT);
                $this->RTQ->get_questionmanager()->edit_question($questionid);

                break;
            case 'deletequestion':

                $questionid = required_param('questionid', PARAM_INT);
                if ($this->RTQ->get_questionmanager()->delete_question($questionid)) {
                    $type = 'success';
                    $message = get_string('qdeletesucess', 'activequiz');
                } else {
                    $type = 'error';
                    $message = get_string('qdeleteerror', 'activequiz');
                }

                $this->RTQ->get_renderer()->setMessage($type, $message);
                $this->RTQ->get_renderer()->print_editpage_header($this->RTQ);
                $this->list_questions();
                $this->RTQ->get_renderer()->end_editpage();

                break;
            case 'listquestions':
                // default is to list the questions
                $this->RTQ->get_renderer()->print_editpage_header($this->RTQ);
                $this->list_questions();
                $this->RTQ->get_renderer()->end_editpage();
                break;
        }
    }

    /**
     * Returns the RTQ instance
     *
     * @return \mod_activequiz\activequiz
     */
    public function getRTQ() {
        return $this->RTQ;
    }

    /**
     * Echos the list of questions using the renderer for activequiz
     *
     */
    protected function list_questions() {

        $questionbankview = $this->get_questionbank_view();
        $questions = $this->RTQ->get_questionmanager()->get_questions();
        $this->RTQ->get_renderer()->editrender_listquestions($questions, $questionbankview);

    }

    /**
     * Gets the question bank view based on the options passed in at the page setup
     *
     * @return string
     */
    protected function get_questionbank_view() {

        $qperpage = optional_param('qperpage', 10, PARAM_INT);
        $qpage = optional_param('qpage', 0, PARAM_INT);


        ob_start(); // capture question bank display in buffer to have the renderer render output

        $questionbank = new \mod_activequiz\activequiz_question_bank_view($this->contexts, $this->pageurl, $this->RTQ->getCourse(), $this->RTQ->getCM());
        $questionbank->display('editq', $qpage, $qperpage, $this->pagevars['cat'], true, true, true);

        return ob_get_clean();
    }


    /**
     * Private function to get parameters
     *
     */
    private function get_parameters() {

        $this->action = optional_param('action', 'listquestions', PARAM_ALPHA);

    }

}

