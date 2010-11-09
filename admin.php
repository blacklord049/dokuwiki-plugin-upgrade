<?php
/**
 * DokuWiki Plugin upgrade (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'admin.php';
require_once DOKU_PLUGIN.'upgrade/VerboseTarLib.class.php';

class admin_plugin_upgrade extends DokuWiki_Admin_Plugin {
    private $tgzurl;
    private $tgzfile;
    private $tgzdir;

    public function __construct(){
        global $conf;

        $branch = 'stable';

        $this->tgzurl  = 'http://github.com/splitbrain/dokuwiki/tarball/'.$branch;
        $this->tgzfile = $conf['tmpdir'].'/dokuwiki-upgrade.tgz';
        $this->tgzdir  = $conf['tmpdir'].'/dokuwiki-upgrade/';
    }

    public function getMenuSort() { return 555; }

    public function handle() {
        if($_REQUEST['step'] && !checkSecurityToken()){
            unset($_REQUEST['step']);
        }
    }

    public function html() {
        global $ID;
        $abrt = false;
        $next = false;

        echo '<h1>' . $this->getLang('menu') . '</h1>';

        $this->_say('<div id="plugin__upgrade">');
        // enable auto scroll
        ?>
        <script language="javascript" type="text/javascript">
            var plugin_upgrade = window.setInterval(function(){
                var obj = $('plugin__upgrade');
                if(obj) obj.scrollTop = obj.scrollHeight;
            },25);
        </script>
        <?php

        // handle current step
        $this->_stepit(&$abrt, &$next);

        // disable auto scroll
        ?>
        <script language="javascript" type="text/javascript">
            window.setTimeout(function(){
                window.clearInterval(plugin_upgrade);
            },50);
        </script>
        <?php
        $this->_say('</div>');

        echo '<form action="" method="get" id="plugin__upgrade_form">';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="upgrade" />';
        echo '<input type="hidden" name="sectok" value="'.getSecurityToken().'" />';
        if($next) echo '<input type="submit" name="step['.$next.']" value="Continue" class="button continue" />';
        if($abrt) echo '<input type="submit" name="step[cancel]" value="Abort" class="button abort" />';
        echo '</form>';
    }

    private function _stepit(&$abrt, &$next){
        global $conf;
        if($conf['safemodehack']){
            $abrt = false;
            $next = false;
            echo $this->locale_xhtml('safemode');
        }

        if(isset($_REQUEST['step']) && is_array($_REQUEST['step'])){
            $step = array_shift(array_keys($_REQUEST['step']));
        }else{
            $step = '';
        }

        if($step == 'cancel'){
            # cleanup
            @unlink($this->tgzfile);
            $this->_rdel($this->tgzdir);
            $step = '';
        }

        if($step){
            $abrt = true;
            $next = false;
            if(!file_exists($this->tgzfile)){
                if($this->_step_download()) $next = 'unpack';
            }elseif(!is_dir($this->tgzdir)){
                if($this->_step_unpack()) $next = 'check';
            }elseif($step != 'upgrade'){
                if($this->_step_check()) $next = 'upgrade';
            }elseif($step == 'upgrade'){
                if($this->_step_copy()) $next = 'cancel';
            }else{
                echo 'uhm. what happened? where am I? This should not happen';
            }
        }else{
            # first time run, show intro
            echo $this->locale_xhtml('step0');
            $abrt = false;
            $next = 'download';
        }
    }

    private function _say(){
        $args = func_get_args();
        echo vsprintf(array_shift($args)."<br />\n",$args);
        flush();
        ob_flush();
    }

    /**
     * Recursive delete
     *
     * @author Jon Hassall
     * @link http://de.php.net/manual/en/function.unlink.php#87045
     */
    private function _rdel($dir) {
        if(!$dh = @opendir($dir)) {
            return;
        }
        while (false !== ($obj = readdir($dh))) {
            if($obj == '.' || $obj == '..') continue;

            if (!@unlink($dir . '/' . $obj)) {
                $this->_rdel($dir.'/'.$obj);
            }
        }
        closedir($dh);
        @rmdir($dir);
    }

    private function _step_download(){
        $this->_say($this->getLang('dl_from'),$this->tgzurl);

        @set_time_limit(120);
        @ignore_user_abort();

        $http = new DokuHTTPClient();
        $http->timeout = 120;
        $data = $http->get($this->tgzurl);

        if(!$data){
            $this->_say($http->error);
            $this->_say($this->getLang('dl_fail'));
            return false;
        }

        if(!io_saveFile($this->tgzfile,$data)){
            $this->_say($this->getLang('dl_fail'));
            return false;
        }

        $this->_say($this->getLang('dl_done',filesize_h(strlen($data)));

        return true;
    }

    private function _step_unpack(){
        global $conf;
        $this->_say('<b>'.$this->getLang('pk_extract').'</b>');

        @set_time_limit(120);
        @ignore_user_abort();

        $tar = new VerboseTarLib($this->tgzfile);
        if($tar->_initerror < 0){
            $this->_say($tar->TarErrorStr($tar->_initerror));
            $this->_say($this->getLang('pk_fail'));
            return false;
        }

        $ok = $tar->Extract(VerboseTarLib::FULL_ARCHIVE,$this->tgzdir,1,$conf['fmode'],'/^(_cs|_test|\.gitignore)/');
        if($ok < 1){
            $this->_say($tar->TarErrorStr($ok));
            $this->_say($this->getLang('pk_fail'));
            return false;
        }

        $this->_say($this->getLang('pk_done'));

        $this->_say($this->getLang('pk_version'),
                    hsc(file_get_contents($this->tgzdir.'/VERSION')),
                    getVersion());
        return true;
    }

    private function _step_check(){
        $this->_say($this->getLang('ck_start'));
        $ok = $this->_traverse('',true);
        #FIXME check unused files
        if($ok){
            $this->_say('<b>'.$this->getLang('ck_done').'</b>');
        }else{
            $this->_say('<b>'.$this->getLang('ck_fail').'</b>');
        }
        return $ok;
    }

    private function _step_copy(){
        $this->_say($this->getLang('cp_start'));
        $ok = $this->_traverse('',false);
        if($ok){
            $this->_say('<b>'.$this->getLang('cp_done').'</b>');
            #FIXME delete unused files
        }else{
            $this->_say('<b>'.$this->getLang('cp_fail').'</b>');
        }
        return $ok;
    }

    private function _traverse($dir,$dryrun){
        $base = $this->tgzdir;
        $ok = true;

        $dh = @opendir($base.'/'.$dir);
        if(!$dh) return;
        while(($file = readdir($dh)) !== false){
            if($file == '.' || $file == '..') continue;
            $from = "$base/$dir/$file";
            $to   = DOKU_INC."$dir/$file";

            if(is_dir($from)){
                if($dryrun){
                    // just check for writability
                    if(!is_dir($to)){
                        if(is_dir(dirname($to)) && !is_writable(dirname($to))){
                            $this->_say('<b>'.$this->getLang('tv_noperm').'</b>'),hsc("$dir/$file"));
                            $ok = false;
                        }
                    }
                }

                // recursion
                if(!$this->_traverse("$dir/$file",$dryrun)){
                    $ok = false;
                }
            }else{
                $fmd5 = md5(@file_get_contents($from));
                $tmd5 = md5(@file_get_contents($to));
                if($fmd5 != $tmd5){
                    if($dryrun){
                        // just check for writability
                        if( (file_exists($to) && !is_writable($to)) ||
                            (!file_exists($to) && is_dir(dirname($to)) && !is_writable(dirname($to))) ){

                            $this->_say('<b>'.$this->getLang('tv_noperm').'</b>'),hsc("$dir/$file"));
                            $ok = false;
                        }else{
                            $this->_say($this->getLang('tv_upd'),hsc("$dir/$file"));
                        }
                    }else{
                        // check dir
                        if(io_mkdir_p(dirname($to))){
                            // copy
                            if(!copy($from,$to)){
                                $this->_say('<b>'.$this->getLang('tv_nocopy').'</b>',hsc("$dir/$file"));
                                $ok = false;
                            }else{
                                $this->_say($this->getLang('tv_done'),hsc("$dir/$file"));
                            }
                        }else{
                            $this->_say('<b>'.$this->getLang('tv_nodir').'</b>',hsc("$dir"));
                            $ok = false;
                        }
                    }
                }
            }
        }
        closedir($dh);
        return $ok;
    }
}

// vim:ts=4:sw=4:et:enc=utf-8:
