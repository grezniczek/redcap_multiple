<?php namespace DE\RUB\MultipleExternalModule;

use ExternalModules\AbstractExternalModule;

class MultipleExternalModule extends AbstractExternalModule
{

    private const MULTIPLE_EM_SESSION_KEY_RECORDS = "multiple-em-selection-store-records";
    private const MULTIPLE_EM_SESSION_KEY_FORMS = "multiple-em-selection-store-forms";
    private const MULTIPLE_EM_SESSION_KEY_INSTANCES = "multiple-em-selection-store-instances";

    function redcap_every_page_before_render($project_id) {

        // Can we show fake data entry pages?
        if (PAGE == "DataEntry/index.php") {
            // Remove form instance from the selection?
            if ($_POST["submit-action"] == "submit-btn-deleteform") {
                $record_id = $_POST["record_id"];
                $instance = $_GET["instance"];
                $event_id = $_GET["event_id"];
                $form = $_GET["page"];
                if ($this->isInstanceSelected($project_id, $record_id, $event_id, $form, $instance))
                {
                    $this->updateInstances($record_id, $event_id, $form, array (
                        $instance => false
                    ));
                }
            }
        }
        // Remove a record from the selection?
        if (PAGE == "DataEntryController:deleteRecord") {
            
            $record_id = $_POST["record"];
            $this->deleteSelectedInstancesForRecord($project_id, $record_id);
            $this->updateRecords(array(
                $record_id => false
            ));
        }



        if (PAGE == "DataEntry/index.php") {
            global $Proj;

            if ($_GET["page"] == "_fake") {
                $allowed = array("record_id", "yesnofield", "yesnoradio1", "record_info_complete", "locktime", "form_2_complete");
                $Proj->forms["_fake"] = array(
                    "form_number" => 1,
                    "menu" => "Edit Multiple",
                    "has_branching" => 0,
                    "fields" => array(),
                );
                foreach ($allowed as $key) {
                    $Proj->forms["_fake"]["fields"][$key] = $Proj->metadata[$key]["element_label"];
                }
            }

            // YES - We can!

            // However, we need to prevent the "Save" event to get to REDCap
            // This can be achieved by setting the <form>'s action attribute to the plugin page

            // Hide Actions, Save & .. Button
            // Change "Save & Exit Form" to "Save Multiple"
            // Hide "Lock this instrument?" row
            // Hide floating save menu
            // Hide "Adding new ..."
            // Set record id to Multiple logo
            // Multiple _complete fields? Add respective form name to "Form Status"

            // Hide Record entry in left side menu!

        }
    }
 
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id , $repeat_instance = 1) {

    }

    function redcap_every_page_top($project_id)
    {


        // TODO - limit scope of this hook

        $debug = $this->getProjectSetting("debug-mode") === true;

        // Inject CSS and JS.
        if (!class_exists("\DE\RUB\MultipleExternalModule\InjectionHelper")) include_once("classes/InjectionHelper.php");
        $ih = InjectionHelper::init($this);
        $ih->js("js/multiple-em.js");
        $ih->css("css/multiple-em.css");

        $dto_selected = $this->loadSelectedRecords($project_id);

        // Link to plugin page in the Data Collection menu.
        $href = $this->getUrl("multiple.php");
        $name = $this->getConfig()["name"];
        $updateUrl = $this->getUrl("ajax/update-selection.php");
        $dto_link = array(
            "href" => $href,
            "name" => $name,
            "clearText" => "Clear", // tt-fy
            "addText" => "Add this record", // tt-fy
            "removeText" => "Remove this record", // tt-fy
        );

        // Record Status Dashboard.
        $dto_rsd = array(
            "init" => strpos(PAGE, "DataEntry/record_status_dashboard.php") !== false,
            "activate" => $this->getProjectSetting("rsd-active") === true,
            "apply" => "Apply", // tt-fy
            "reset" => "Reset", // tt-fy
            "addAll" => "Add all", // tt-fy
            "removeAll" => "Remove all", // tt-fy
        );

        // Record Home Page.
        $dto_rhp = array(
            "init" => false,
            "activate" => $this->getProjectSetting("rhp-active") === true,
            "rit" => array(),
            "fei" => array(),
            "viewPresets" => array(),
            "updatePresets" => array(),
            "deleteFormsConfirmTitle" => $this->tt("modal_delete_forms_title"),
            "deleteFormsConfirmText" => $this->tt("modal_delete_forms_text"),
        );
        // Do we have a record?
        if (strpos(PAGE, "DataEntry/record_home.php") !== false) {
            $record_id = isset($_GET["id"]) && !isset($_GET["auto"]) ? $_GET["id"] : null;
            if ($record_id != null) {
                $dto_rhp["init"] = true;
                // Use Project Data Structure to get
                // all repeating forms on all events and assemble a list
                // of ids like "repeat_instrument_table-80-repeating_store"
                // i.e. "repeat_instrument_table-" + event_id + "-" + form name
                // Then, JS side can use this to add UI elements
                if (!class_exists("\DE\RUB\MultipleExternalModule\Project")) include_once("classes/Project.php");
                $project = Project::get($this->framework, $project_id);
                // /** @var \ExternalModules\Project */
                // $project = $this->framework->getProject($project_id);
                $project->grantUserPermissions(USERID);
                $record = $project->getRecord($record_id);
                // Repeating forms
                $repeating = $project->getEventsForms(false, true);
                $dto_rhp["rit"] = array();
                foreach ($repeating as $this_event_id => $this_forms) {
                    foreach ($this_forms as $form => $_) {
                        $rit_key = "repeat_instrument_table-{$this_event_id}-{$form}";
                        $dto_rhp["rit"][$rit_key] = $this->loadSelectedInstances($project_id, $record_id, $this_event_id, $form);
                    }
                }
                // Non-repeating forms
                $dto_rhp["nrf"] = array_keys($project->getFormsEvents(false, false));
                // Get all event grid table forms that have a gray status (i.e. never saved)
                // fei = form_name-event_id-instance
                $fei_nodata = array();
                $events = $project->getEvents();
                foreach ($events as $this_event_id) {
                    $event_repeating = $project->isEventRepeating($this_event_id);
                    $gray_forms = $record->getFormStatus(null, $this_event_id);
                    foreach ($gray_forms as $this_form => $this_instancestatus) {
                        if ($project->isFormRepeating($this_form)) continue;
                        foreach ($this_instancestatus as $this_instance => $this_status) {
                            if ($this_status === null) {
                                $fei_i = ($event_repeating && $this_instance != 1) ? $this_instance : "null";
                                $fei_nodata[] = "{$this_form}-{$this_event_id}-{$fei_i}";
                            }
                        }
                        
                    }
                }
                $dto_rhp["fei_nodata"] = $fei_nodata;
                $fei_selected_saved = $this->loadSelectedForms($project_id, $record_id);
                $fei_selected = array_diff($fei_selected_saved, $fei_nodata);
                if (count($fei_selected_saved) != count($fei_selected)) {
                    // Re-save in case of discrepancy .. maybe because somebody has deleted a form concurrently
                    $this->saveSelectedForms($project_id, $record_id, $fei_selected);
                }
                $dto_rhp["fei"] = $fei_selected;
            }
        }
        // Instance view presets - TODO
        $dto_rhp["viewPresets"] = array(
            array(
                "id" => 1,
                "name" => "Test",
                "fields" => array("lap_id", "lap_elapsed")
            )
        );
        
        // User rights - TODO
        global $user_rights;
        $dto_user_rights = array(
            "design" => $user_rights["design"] != 0,
            "record_delete" => $user_rights["record_delete"] != 0,
            "lock_record" => $user_rights["lock_record"] != 0,
            "lock_record_multiform" => $user_rights["lock_record_multiform"] != 0,
            "data_access_groups" => $user_rights["data_access_groups"] != 0,
        );
        
        $this->includeDeleteConfirmationModal();
            
        // Transfer data to the JavaScript implementation.
?>
        <script>
            var DTO = window.ExternalModules.MultipleEM_DTO;
            DTO.debug = <?= json_encode($debug) ?>;
            DTO.name = <?= json_encode($name) ?>;
            DTO.updateUrl = <?= json_encode($updateUrl) ?>;
            DTO.link = <?= json_encode($dto_link) ?>;
            DTO.selected = <?= json_encode($dto_selected) ?>;
            DTO.rsd = <?= json_encode($dto_rsd) ?>;
            DTO.rhp = <?= json_encode($dto_rhp) ?>;
            DTO.userRights = <?= json_encode($dto_user_rights) ?>;
        </script>
    <?php
    }



    /**
     * Deletes all currently selected forms of the given record.
     * @param string $record_id
     */
    function deleteRecordForms($record_id) {
        $pid = $this->getProjectId();
        if (!class_exists("\DE\RUB\MultipleExternalModule\Project")) include_once("classes/Project.php");
        $project = Project::get($this->framework, $pid);
        $project->grantUserPermissions(USERID);
        $record = $project->getRecord($record_id);

        // Non-repeating forms
        $selected = $this->loadSelectedForms($pid, $record_id);
        // [ "form_name-event_id-instance", ... ]
        foreach ($selected as $fei) {
            $parts = explode("-", $fei);
            $form = $parts[0];
            $event_id = $parts[1] * 1;
            $instance = $parts[2] == "null" ? null : $parts[2] * 1;
            if ($project->isEventRepeating($event_id) && $instance === null) {
                // Fix for repeating event first instance
                $instance = 1;
            }
            try {
                $record->deleteForm($form, $event_id, $instance);
                // Remove from selection
                unset($selected[array_search($fei, $selected)]);
            }
            catch (\Throwable $e) {
                throw new \Exception("Form deletion failed: {$e->getMessage()}");
            }
            finally {
                $this->saveSelectedForms($pid, $record_id, $selected);
            }
        }

        // Repeating forms
        $selected = $this->loadSelectedInstances($pid, $record_id);
        // [
        //   event_id => [
        //     form_name => [ 
        //       instance_number,
        //       ...
        //     ]
        //   ]
        // ]
        try {
            foreach ($selected as $event_id => $forms) {
                foreach ($forms as $form => $instances) {
                    foreach ($instances as $instance) {
                        $record->deleteForm($form, $event_id, $instance);
                        // Remove from selection
                        unset($selected[$event_id][$form][array_search($instance, $instances)]);
                    }
                }
            }
        }
        catch (\Throwable $e) {
            throw new \Exception("Form deletion failed: {$e->getMessage()}");
        }
        finally {
            $this->saveSelectedInstances($pid, $record_id, null, null, $selected);
        }
    }





    /**
     * Sets the lock state for the currently selected repeating form instances of the given record.
     * @param string $record_id
     * @param bool $locked
     */
    function setFormsLockState($record_id, $locked) {
        $pid = $this->getProjectId();
        if (!class_exists("\DE\RUB\MultipleExternalModule\Project")) include_once("classes/Project.php");
        $project = Project::get($this->framework, $pid);
        $project->grantUserPermissions(USERID);
        $record = $project->getRecord($record_id);
        $selected = $this->loadSelectedInstances($pid, $record_id);
        // [
        //   event_id => [
        //     form_name => [ 
        //       instance_number,
        //       ...
        //     ]
        //   ]
        // ]
        foreach ($selected as $event_id => $forms) {
            foreach ($forms as $form => $instances) {
                if ($locked) {
                    $record->lockFormInstances($form, $instances, $event_id);
                }
                else {
                    $record->unlockFormInstances($form, $instances, $event_id);
                }
            }
        }
        $selected = $this->loadSelectedForms($pid, $record_id);
        foreach ($selected as $fei) {
            $parts = explode("-", $fei);
            $form = $parts[0];
            $event_id = $parts[1];
            $instance = $parts[2];
            $instance = ($instance === "null") ? null : $instance * 1;
            if ($locked) {
                $record->lockForms($form, $event_id, $instance);
            }
            else {
                $record->unlockForms($form, $event_id, $instance);
            }
        }
    }




    private function loadSelectedRecords($pid) {
        return isset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_RECORDS][$pid]) ?
            $_SESSION[self::MULTIPLE_EM_SESSION_KEY_RECORDS][$pid] :
            array();
    }

    private function saveSelectedRecords($pid, $selected) {
        $_SESSION[self::MULTIPLE_EM_SESSION_KEY_RECORDS][$pid] = $selected;
    }

    public function updateRecords($diff) {
        $pid = $this->getProjectId();
        $records = $this->loadSelectedRecords($pid);
        foreach ($diff as $record_id => $is_selected) {
            if ($is_selected) {
                array_push($records, "$record_id");
            } else {
                $pos = array_search("$record_id", $records, true);
                if ($pos !== false) {
                    array_splice($records, $pos, 1);
                }
                $this->clearAllInstances("$record_id");
                $this->clearAllForms("$record_id");
            }
        }
        $this->saveSelectedRecords($pid, array_values(array_unique($records, SORT_STRING)));
    }

    public function clearRecords() {
        $pid = $this->getProjectId();
        $this->saveSelectedRecords($pid, array());
        $this->clearAllInstances();
        $this->clearAllForms();
    }

    public function deleteRecords() {
        $pid = $this->getProjectId();
        if (!class_exists("\DE\RUB\MultipleExternalModule\Project")) include_once("classes/Project.php");
        $project = Project::get($this->framework, $pid);
        $project->grantUserPermissions(USERID);
        $records = $this->loadSelectedRecords($pid);
        $deleted = array();
        try {
            foreach ($records as $record_id) {
                $record = $project->getRecord($record_id);
                $record->delete();
                $deleted[] = $record_id;
            }
        }
        catch (\Throwable $e) {
            throw new \Exception("An error occured while deleting records: {$e->getMessage()}", 0, $e);
        }
        finally {
            $this->saveSelectedRecords($pid, array_diff($records, $deleted));
        }
    }






    public function clearAllInstances($record_id = null) {
        $pid = $this->getProjectId();
        if ($record_id) {
            unset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id]);
        } else {
            unset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid]);
        }
    }

    public function updateForms($record_id, $diff) {
        $pid = $this->getProjectId();
        $forms = $this->loadSelectedForms($pid, $record_id);
        foreach ($diff as $fei => $is_selected) {
            if ($is_selected) {
                array_push($forms, $fei);
            } else {
                $pos = array_search($fei, $forms);
                if ($pos !== false) {
                    array_splice($forms, $pos, 1);
                }
            }
        }
        $this->saveSelectedForms($pid, $record_id, $forms);
    }

    public function clearAllForms($record_id = null) {
        $pid = $this->getProjectId();
        if ($record_id) {
            unset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_FORMS][$pid][$record_id]);
        } else {
            unset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_FORMS][$pid]);
        }
    }

    private function loadSelectedForms($pid, $record_id) {
        return isset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_FORMS][$pid][$record_id]) ?
            $_SESSION[self::MULTIPLE_EM_SESSION_KEY_FORMS][$pid][$record_id] :
            array();
    }

    private function saveSelectedForms($pid, $record_id, $forms) {
        $_SESSION[self::MULTIPLE_EM_SESSION_KEY_FORMS][$pid][$record_id] = $forms;
    }

    private function loadSelectedInstances($pid, $record_id, $event_id = null, $form = null) {
        if ($form == null && $event_id == null) {
            return isset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id]) ?
                $_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id] :
                array();
        }
        else if ($form == null) {
            return isset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id]) ?
            $_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id] :
            array();
        }
        else {
        return isset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id][$form]) ?
            $_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id][$form] :
            array();
        }
    }

    private function saveSelectedInstances($pid, $record_id, $event_id, $form, $instances) {
        if ($event_id === null && $form === null) {
            $_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id] = $instances;
        }
        else if ($form === null) {
            $_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id] = $instances;
        }
        else {
            $_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id][$form] = $instances;
        }
    }

    private function isInstanceSelected($pid, $record_id, $event_id, $form, $instance) {
        return isset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id][$form][$instance]);
    }

    private function deleteSelectedInstancesForRecord($pid, $record_id) {
        unset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id]);
    }

    private function deleteSelectedInstancesForRecordEvent($pid, $record_id, $event_id) {
        unset($_SESSION[self::MULTIPLE_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id]);
    }

    public function updateInstances($record_id, $diffs) {
        $pid = $this->getProjectId();
        foreach ($diffs as $rit => $diff) {
            $parts = explode("-", $rit);
            $event_id = $parts[1] * 1;
            $form = $parts[2];
            $instances = $this->loadSelectedInstances($pid, $record_id, $event_id, $form);
            foreach ($diff as $instance => $is_selected) {
                if ($is_selected) {
                    array_push($instances, $instance);
                } else {
                    $pos = array_search($instance, $instances, true);
                    if ($pos !== false) {
                        array_splice($instances, $pos, 1);
                    }
                }
            }
            $this->saveSelectedInstances($pid, $record_id, $event_id, $form, array_values(array_unique($instances, SORT_NUMERIC)));
        }
    }

    private function includeDeleteConfirmationModal() {
        /** @var \ExternalModules\Framework */
        $fw = $this->framework;
        ?>
        <div class="modal fade multiple-em-delete-confirmation-modal" data-backdrop="static" tabindex="-1" role="dialog" aria-labelledby="multiple-em-delete-confirmation-model-staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="multiple-em-delete-confirmation-model-staticBackdropLabel">Title</h5>
                        <button type="button" class="close" data-em-modal-action aria-label="<?=$fw->tt("modal_close")?>">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        ...
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-em-modal-action><?=$fw->tt("modal_cancel")?></button>
                        <button type="button" class="btn btn-danger multiple-em-confirmed" data-em-modal-action="confirm"><?=$fw->tt("modal_delete")?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

} // MultipleExternalModule
