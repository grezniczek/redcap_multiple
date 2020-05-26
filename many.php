<?php namespace DE\RUB\ManyExternalModule;
//
// Many EM - Plugin
//
class ManyEM_PluginPage
{
    /** @var ManyExternalModule $module Many EM instance */
    private $module;
    
    /**
     * @param ManyExternalModule $module Many EM instance
     */
    public function __construct($module) {
        $this->module = $module;
    }

    public function render() {
        /** @var \ExternalModules\Framework $fw */
        $fw = $this->module->framework;

        // TODO
        print "TODO";
    }
}

//
// REDCap Header
//
require_once APP_PATH_DOCROOT . "ProjectGeneral/header.php";
$many = new ManyEM_PluginPage($module);
//
// Plugin Page
?>
<div class="many-em-pagecontainer">
    <h3><i class="far fa-check-square many-em-logo"></i> Many</h3>
    <?php $many->render(); ?>
</div>
<?php 
//
// REDCap Footer
//
require_once APP_PATH_DOCROOT . "ProjectGeneral/footer.php";