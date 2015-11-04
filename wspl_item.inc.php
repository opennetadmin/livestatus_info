<?php
global $livestatusservers, $base, $conf, $baseURL, $images, $onabase;

$title_right_html = '';
$title_left_html  = '';
$modbodyhtml = '';
$modjs = '';

// Get info about this file name
$onainstalldir = dirname($base);
$file = str_replace($onainstalldir.'/www', '', __FILE__);
$thispath = dirname($file);

// future config options
$refresh_interval = '600000'; // every 10 minutes
$boxheight = '300px';
$divid = 'desktopnagstats';
$minimizedefault = '';

// Include the livestatus library
include(dirname(__FILE__)."/livestatus.php");
// Include the livestatus conf
$conffile = (file_exists($onabase.'/etc/livestatus_info.conf.php')) ? $onabase.'/etc/livestatus_info.conf.php' : dirname(__FILE__).'/livestatus_info.conf.php';
include($conffile);



/*
MISC TODO
* fix the stupid refresh fit that the wspl module is doing
then host display will check that before contacting the server its on.

* have the ack,notify, buttons be secured with a new role?
* finish/make the installer
* allow a way to adjust the Filter in the query, probably just click the ok,warn,crit links to toggle

*/

if ($extravars['window_name'] == 'display_host') $divid = $divid.'_host';

// Display only on the desktop
if ($extravars['window_name'] == 'html_desktop' or $extravars['window_name'] == 'display_host') {

    if($extravars['window_name'] == 'display_host') { $boxheight = '150px'; $divid = 'hostnagstats'; }

    $title_left_html .= <<<EOL
        &nbsp;Livestatus &nbsp;&nbsp;<span style="font-size: small;" id="{$divid}_cnt" onclick="el('{$modulename}_content').style.display=''; el('{$modulename}_dropdown').src='{$min_img}';"></span>
EOL;

    $title_right_html .= <<<EOL
        <a title="Reload livestatus info" onclick="el('{$divid}').innerHTML = '<center>Reloading...</center>';xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"><img src="{$images}/silk/arrow_refresh.png" border="0"></a>
EOL;


    $modbodyhtml .= <<<EOL
<form id="naginfo_form" onSubmit="return false;">
<input type="hidden" name="divname" value="{$divid}">
<input type="hidden" name="host_name" value="{$record['fqdn']}">
</form>
<div id="{$divid}" style="height: {$boxheight};overflow-y: auto;overflow-x:hidden;font-size:small">
{$conf['loading_icon']}
</div>
EOL;

    // run the function that will update the content of the plugin.
    $modjs = <<< EOL
    xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');
EOL;
/*
function loadlivestatus() {
//    console.log('winsubmit');
    el('{$divid}').innerHTML = '<center>Reloading...</center>';
    xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');
}
loadlivestatus();
liverefresh = undefined;
if (liverefresh !== undefined) { //interval is already running
    clearInterval(liverefresh);
//    console.log('clear');
    liverefresh = undefined;
    loadlivestatus();
} else {
    clearInterval(liverefresh);
    liverefresh = setInterval(loadlivestatus,{$refresh_interval});
//    console.log('reloaded');
}

EOL;
*/

$divid='';

}




/*
Gather all of the data from livestatus and format them in a table.
Then update the nagiosstatus innerHTML with the data.
*/
function ws_display_nagiosstats($window_name, $form='') {
    global $livestatusservers, $conf, $self, $onadb, $base, $images, $baseURL;

    // Get info about this file name
    $onainstalldir = dirname($base);
    $file = str_replace($onainstalldir.'/www', '', __FILE__);
    $thispath = dirname($file);

    // If an array in a string was provided, build the array and store it in $form
    $form = parse_options_string($form);

    $nok_count=0;
    $warn_count=0;
    $crit_count=0;
    $u_count=0;

    // Adding this here until I get it added to the core javascript code
    $jssleep = <<<EOL
function sleep(milliseconds) {
  var start = new Date().getTime();
  for (var i = 0; i < 1e7; i++) {
    if ((new Date().getTime() - start) > milliseconds){
      break;
    }
  }
}
EOL;

    // Get the data

    // Communicate with all the livestat servers
    foreach ($livestatusservers as $livestatusserver => $livestatusconf) {

        // Define the list of columns we want
        $columns=array('description',
                       'acknowledged',
                       'host_acknowledged',
                       'active_checks_enabled',
                       'host_active_checks_enabled',
                       'notifications_enabled',
                       'host_notifications_enabled',
                       'state',
                       'host_state',
                       'host_plugin_output',
                       'host_name');

        // Convert the columns to text to use in the livestatus query
        foreach($columns as $col) { $columns_text.="$col "; }

        // Define query for livestatus

        if ($form['host_name']) {
            $query = <<<EOL
GET services
Columns: ${columns_text}
Filter: host_name = ${form['host_name']}
EOL;
        } else {
            $query = <<<EOL
GET services
Columns: ${columns_text}
Filter: state > 0
EOL;
        }


        // Connect and run query
        list($failstat,$errstring)=connectLiveSocket($livestatusconf);
        if (!$failstat) {
            list($querystat,$services)=queryLivestatus($query);
            if (!is_array($services)) { $htmllines .= "ERROR: {$livestatusconf[socketAddress]} $services<br>"; }
            closeLiveSocket();
        } else {
            $htmllines .= "ERROR: $errstring";
        }

        // reverse array so things are alphabetical
        $services=array_reverse($services);

        // Loop through service information
        foreach ($services as $service) {

            // Save this instances livestatus server
            $mylivestatusserver=$livestatusserver;
            $mylivestatusURL=$livestatusconf['viewURL'];

            // Turn all of the json elements into $v_<colname> variables
            foreach($columns as $col) { ${'v_'.$col} = $service[array_search($col,$columns)]; }

//echo "<pre>";
//print_r($service);
//echo "</pre>";


            $y++;
            // Display actively checked services that are warn or crit but not acknowledged
            if ((!$form['host_name'] and
                $v_acknowledged == 0 and
                $v_active_checks_enabled == 1)
                or
                $form['host_name']
               ) {
                $nok_count++;
                switch ($v_state) {
                    case 0:
                        $color = "#C0FFC0"; break;
                    case 1:
                        $color = "#F7FF5E"; $warn_count++; $nok_count--; break;
                    case 2:
                        $color = "#FF7375"; $crit_count++; $nok_count--; break;
                    default:
                        $color = "#EEEEEE"; $u_count++; $nok_count--; break;
                } //end switch

                // trim down the line so it fits better
                $svc_name = truncate($v_description,45);

                // Process host level check status
                if ($lasthost != $v_host_name.$livestatusserver and (($v_host_state>0 and !$form['host_name']) or $form['host_name'])) {
                    // keep track of server/host for loops
                    $lasthost = $v_host_name.$livestatusserver;
                    $nok_count++;
                    switch ($v_host_state) {
                        case 0:
                            $hcolor = "#C0FFC0"; break;
                        case 1:
                            $hcolor = "#F7FF5E"; $warn_count++; $nok_count--; break;
                        case 2:
                            $hcolor = "#FF7375"; $crit_count++; $nok_count--; break;
                        default:
                            $hcolor = "#EEEEEE"; $u_count++; $nok_count--; break;
                    } //end switch
                    $htmllines .= <<<EOL
            <tr onMouseOver="this.className='row-highlight';" onMouseOut="this.className='row-normal';">
EOL;
                if ($form['divname'] != "hostnagstats") {
                    $htmllines .= <<<EOL
                <td class="list-row"><a title="display_host: {$v_host_name}" onclick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_host\', \'host=>{$v_host_name}\', \'display\')');">{$v_host_name}</a></td>
                <td class="list-row"><a href="{$mylivestatusURL}{$v_host_name}" title="View host on livestatus server" target="blank">{$mylivestatusserver}</a></td>
EOL;
                }

                // Ack button
                if ($v_host_acknowledged == 1) {
                    $ack_img = "src='{$images}/silk/tick.png' width='14'";
                    $ack_cmd = "REMOVE_HOST_ACKNOWLEDGEMENT;{$v_host_name}";
                    $ack_tip = "Click to remove Acknowledgement";
                } else {
                    // The width here hides the image
                    $ack_img = "src='{$images}/silk/tick.png' width='0'";
                }
                // active checks button
                if ($v_host_active_checks_enabled == 1) {
                    $actchk_img = "{$images}/silk/cog.png";
                    $actchk_cmd = "DISABLE_HOST_CHECK;{$v_host_name}";
                    $actchk_tip = "Click to Disable Active Checks";
                } else {
                    $actchk_img = "{$images}/silk/cog_delete.png";
                    $actchk_cmd = "ENABLE_HOST_CHECK;{$v_host_name}";
                    $actchk_tip = "Click to Enable Active Checks";
                }
                // Notification button
                if ($v_host_notifications_enabled) {
                    $notify_img = "{$images}/silk/sound_none.png";
                    $notify_cmd = "DISABLE_HOST_NOTIFICATIONS;{$v_host_name}";
                    $notify_tip = "Click to Disable Notifications";
                } else {
                    $notify_img = "{$images}/silk/sound_mute.png";
                    $notify_cmd = "ENABLE_HOST_NOTIFICATIONS;{$v_host_name}";
                    $notify_tip = "Click to Enable Notifications";
                }

                $htmllines .= <<<EOL
                <td class="list-row" style='background-color: {$hcolor};' onMouseOver="wwTT(this, event,
                                            'id', 'tt_naginfo_{$y}',
                                            'type', 'velcro',
                                            'styleClass', 'wwTT_ca_info',
                                            'direction', 'south',
                                            'javascript', 'xajax_window_submit(\'{$file}\', xajax.getFormValues(\'naginfo_form_{$y}\'), \'nagios_popup_info\');'
                                           );"><b>[{$livestatusserver}] HOST:</b> {$v_host_plugin_output}
                  <form id="naginfo_form_{$y}" onSubmit="return false;">
                  <input type="hidden" name="id" value="tt_naginfo_{$y}">
                  <input type="hidden" name="host_type" value="true">
                  <input type="hidden" name="host_name" value="{$v_host_name}">
                  <input type="hidden" name="description" value="{$v_description}">
                  <input type="hidden" name="livestatusserver" value="{$livestatusserver}">
                  </form>
                </td>
                <td class="list-row" style="padding:0px 2px;"><img src="{$actchk_img}" border="0" width="14" title="{$actchk_tip}" onClick="xajax_window_submit('{$file}', 'cmd=>{$actchk_cmd},serverinstance=>{$livestatusserver}', 'livestatus_cmd');{$jssleep} sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"></td>
                <td class="list-row" style="padding:0px 2px;"><img src="{$notify_img}" border="0" width="16" title="{$notify_tip}" onClick="xajax_window_submit('{$file}', 'cmd=>{$notify_cmd},serverinstance=>{$livestatusserver}', 'livestatus_cmd');{$jssleep} sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"></td>
                <td class="list-row" style="padding:0px 2px;"><img {$ack_img} border="0" title="{$ack_tip}" onClick="xajax_window_submit('{$file}', 'cmd=>{$ack_cmd},serverinstance=>{$livestatusserver}', 'livestatus_cmd');{$jssleep} sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"></td>
                <td>&nbsp;&nbsp;&nbsp;&nbsp;</td>
            </tr>
EOL;

                //<td class="list-row"><img src="{$notify_img}" border="0" width="16" title="{$notify_tip}" onClick="xajax_window_submit('{$file}', 'cmd=>{$notify_cmd},serverinstance=>{$livestatusserver}', 'livestatus_cmd');{$jssleep} sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"></td>
                   // Increment our counter so the next record is good
                   $y++; 
               } // End if y==1





                $htmllines .= <<<EOL
            <tr onMouseOver="this.className='row-highlight';" onMouseOut="this.className='row-normal';">
EOL;

                if ($form['divname'] != "hostnagstats") {
                    $htmllines .= <<<EOL
                <td class="list-row"><a title="display_host: {$v_host_name}" onclick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_host\', \'host=>{$v_host_name}\', \'display\')');">{$v_host_name}</a></td>
                <td class="list-row"><a href="{$mylivestatusURL}{$v_host_name}" title="View host on livestatus server" target="blank">{$mylivestatusserver}</a></td>
EOL;
                }


                // Ack button
                if ($v_acknowledged == 1) {
                    $ack_img = "src='{$images}/silk/tick.png' width='14'";
                    $ack_cmd = "REMOVE_SVC_ACKNOWLEDGEMENT;{$v_host_name};{$v_description}";
                    $ack_tip = "Click to remove Acknowledgement";
                } else {
                    // The width here hides the image
                    $ack_img = "src='{$images}/silk/tick.png' width='0'";
                }
                // active checks button
                if ($v_active_checks_enabled == 1) {
                    $actchk_img = "{$images}/silk/cog.png";
                    $actchk_cmd = "DISABLE_SVC_CHECK;{$v_host_name};{$v_description}";
                    $actchk_tip = "Click to Disable Active Checks";
                } else {
                    $actchk_img = "{$images}/silk/cog_delete.png";
                    $actchk_cmd = "ENABLE_SVC_CHECK;{$v_host_name};{$v_description}";
                    $actchk_tip = "Click to Enable Active Checks";
                }
                // Notification button
                if ($v_notifications_enabled) {
                    $notify_img = "{$images}/silk/sound_none.png";
                    $notify_cmd = "DISABLE_SVC_NOTIFICATIONS;{$v_host_name};{$v_description}";
                    $notify_tip = "Click to Disable Notifications";
                } else {
                    $notify_img = "{$images}/silk/sound_mute.png";
                    $notify_cmd = "ENABLE_SVC_NOTIFICATIONS;{$v_host_name};{$v_description}";
                    $notify_tip = "Click to Enable Notifications";
                }

                $htmllines .= <<<EOL
                <td class="list-row" style='background-color: {$color};' onMouseOver="wwTT(this, event,
                                            'id', 'tt_naginfo_{$y}',
                                            'type', 'velcro',
                                            'styleClass', 'wwTT_ca_info',
                                            'direction', 'south',
                                            'javascript', 'xajax_window_submit(\'{$file}\', xajax.getFormValues(\'naginfo_form_{$y}\'), \'nagios_popup_info\');'
                                           );">{$svc_name}
                  <form id="naginfo_form_{$y}" onSubmit="return false;">
                  <input type="hidden" name="id" value="tt_naginfo_{$y}">
                  <input type="hidden" name="host_name" value="{$v_host_name}">
                  <input type="hidden" name="description" value="{$v_description}">
                  <input type="hidden" name="livestatusserver" value="{$livestatusserver}">
                  </form>
                </td>
                <td class="list-row" style="padding:0px 2px;"><img src="{$actchk_img}" border="0" width="14" title="{$actchk_tip}" onClick="xajax_window_submit('{$file}', 'cmd=>{$actchk_cmd},serverinstance=>{$livestatusserver}', 'livestatus_cmd');{$jssleep} sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"></td>
                <td class="list-row" style="padding:0px 2px;"><img src="{$notify_img}" border="0" width="16" title="{$notify_tip}" onClick="xajax_window_submit('{$file}', 'cmd=>{$notify_cmd},serverinstance=>{$livestatusserver}', 'livestatus_cmd');{$jssleep} sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"></td>
                <td class="list-row" style="padding:0px 2px;"><img {$ack_img} border="0" title="{$ack_tip}" onClick="xajax_window_submit('{$file}', 'cmd=>{$ack_cmd},serverinstance=>{$livestatusserver}', 'livestatus_cmd');{$jssleep} sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');"></td>
                <td>&nbsp;&nbsp;&nbsp;&nbsp;</td>
            </tr>
EOL;
            } // End if hostname stuffs
    }
 }

    
    // If we actually have information.. print the table
    if (!$htmllines) {
        $htmllines = "<tr><td>Host may not be in nagios, no status found.</td></tr>";
    }
    $html .= '<table class="list-box" cellspacing="0" border="0" cellpadding="0">';
    $html .= $htmllines;
    $html .= "</table>";


    //Colorize the title if we have some counts
    $warntitlecolor="Warn: {$warn_count} ";
    $crittitlecolor="Crit: {$crit_count} ";
    if ($warn_count) $warntitlecolor="<span style='background-color: #F7FF5E;'>Warn: {$warn_count} </span>";
    if ($crit_count) $crittitlecolor="<span style='background-color: #FF7375;'>Crit: {$crit_count} </span>";

    // Create HTML for the top bar
//<img border="0" title="View Host on instance: {$mylivestatusserver}" src="{$images}/silk/tick.png" onClick='window.open("{$mylivestatusURL}{$v_host_name}");'>
    $topbarhtml = <<<EOL
OK: {$nok_count} &nbsp;{$warntitlecolor}&nbsp;{$crittitlecolor}&nbsp;?: {$u_count}
EOL;

    // Insert the new table into the window
    $response = new xajaxResponse();
    $response->addAssign($form['divname'], "innerHTML", $html);
    //$response->addAssign($form['divname'].'_cnt', "innerHTML", "OK: {$nok_count} &nbsp;{$warntitlecolor}&nbsp;{$crittitlecolor}&nbsp;?: {$u_count}");
    $response->addAssign($form['divname'].'_cnt', "innerHTML", "{$topbarhtml}");
    $response->addScript($js);
    return($response->getXML());
}





function ws_nagios_popup_info($window_name, $form='') {
    global $livestatusservers, $conf, $self, $onadb, $base;
    global $font_family, $color, $style, $images;
    $html = $js = '';

    // Get info about this file name
    $onainstalldir = dirname($base);
    $file = str_replace($onainstalldir.'/www', '', __FILE__);
    $thispath = dirname($file);

    $response = new xajaxResponse();

    // If an array in a string was provided, build the array and store it in $form
    $form = parse_options_string($form);


    $style['content_box'] = <<<EOL
        padding: 2px 4px;
        vertical-align: top;
EOL;

    // WARNING: this one's different than most of them!
    $style['label_box'] = <<<EOL
        font-weight: bold;
        cursor: move;

EOL;


        // Connect to livestatus instance
        // Define the list of columns we want
        $columns=array('description',
                       'acknowledged',
                       'comments_with_info',
                       'host_acknowledged',
                       'active_checks_enabled',
                       'state',
                       'host_name',
                       'host_state',
                       'host_plugin_output',
                       'plugin_output',
                       'last_check',
                       'host_last_check',
                       'active_checks_enabled',
                       'host_active_checks_enabled',
                       'host_notifications_enabled',
                       'notifications_enabled');

        // Convert the columns to text to use in the livestatus query
        foreach($columns as $col) { $columns_text.="$col "; }

        // Define query for livestatus
        $query = <<<EOL
GET services
Columns: ${columns_text}
Filter: host_name = ${form['host_name']}
Filter: description = ${form['description']}
EOL;

        // Connect and run query
        list($failstat,$errstring)=connectLiveSocket($livestatusservers[$form['livestatusserver']]);
        if (!$failstat) {
            list($querystat,$service)=queryLivestatus($query);
            if (!is_array($service)) { $html .= "ERROR: {$livestatusservers[$form['livestatusserver']][socketAddress]} $service<br>"; }
            closeLiveSocket();
        } else {
            $html .= "ERROR: {$livestatusservers[$form['livestatusserver']][socketAddress]} $errstring";
            $response->addScript("el('{$form['id']}').style.visibility = 'hidden';");
            $response->addAssign($form['id'], "innerHTML", $html);
            $response->addScript("wwTT_position('{$form['id']}'); el('{$form['id']}').style.visibility = 'visible';");
            return($response->getXML());
        }

    // get the first (only) entry returned
    $service=$service[0];

    // Turn all of the json elements into $v_<colname> variables
    foreach($columns as $col) { ${'v_'.$col} = $service[array_search($col,$columns)]; }

    // Clean up comments
    if ($v_comments_with_info) {
      foreach ($v_comments_with_info as $comment) {
        $commentTxt.="[{$comment[1]}] {$comment[2]}<br>";
      }
    }

    // Set a default command type for services
    $cmdtype='SVC';

    // Its a host check look at the host status info
    if ($form['host_type']) {
        $cmdtype='HOST';
        $v_acknowledged=$v_host_acknowledged;
        $v_state=$v_host_state;
        $v_active_checks_enabled=$v_host_active_checks_enabled;
        $v_notifications_enabled=$v_host_notifications_enabled;
        $v_plugin_output=$v_host_plugin_output;
        $v_last_check=$v_host_last_check;
        // not sure if this is always ping or not??
        $v_description='HOST: PING';
    }

    // Clean up some data
    switch ($v_state) {
        case 0:
            $stat_desc = 'OK'; break;
        case 1:
            $stat_desc = 'Warning'; break;
        case 2:
            $stat_desc = 'Critical'; break;
        default:
            $stat_desc = 'Unknown'; break;
    }
    $v_last_check = date($conf['date_format'],$v_last_check);

    // ack checks button
    if ($v_acknowledged == 0) {
        //$ack_cmd = "ENABLE_SVC_CHECK;{$v_host_name};{$v_description}";
        $ack_tip = "Click to Acknowledge";
        $v_acknowledged  = 'No';
        $ackjs="el('ackrow').innerHTML='';el('livestatus_ackform').style.display='';";
    } else {
        $ack_cmd = "REMOVE_{$cmdtype}_ACKNOWLEDGEMENT;{$v_host_name};{$v_description}";
        $ack_tip = "Click to Remove Acknowledgement";
        $v_acknowledged  = 'Yes';
        $ackjs="xajax_window_submit('{$file}', 'cmd=>{$ack_cmd},serverinstance=>{$form['livestatusserver']},tooltipid=>{$form['id']}', 'livestatus_cmd');";
    }

    // active checks button
    if ($v_active_checks_enabled == 0) {
        $actchk_cmd = "ENABLE_{$cmdtype}_CHECK;{$v_host_name};{$v_description}";
        $actchk_tip = "Click to Enable Active Checks";
        $v_active_checks_enabled  = 'No';
    } else {
        $actchk_cmd = "DISABLE_{$cmdtype}_CHECK;{$v_host_name};{$v_description}";
        $actchk_tip = "Click to Disable Active Checks";
        $v_active_checks_enabled  = 'Yes';
    }


    // Notification button
    if ($v_notifications_enabled == 0) {
        $notify_cmd = "ENABLE_{$cmdtype}_NOTIFICATIONS;{$v_host_name};{$v_description}";
        $notify_tip = "Click to Enable Notifications";
        $v_notifications_enabled  = 'No';
    } else {
        $notify_cmd = "DISABLE_{$cmdtype}_NOTIFICATIONS;{$v_host_name};{$v_description}";
        $notify_tip = "Click to Disable Notifications";
        $v_notifications_enabled  = 'Yes';
    }



    $html .= <<<EOL

    <table style="{$style['content_box']}" cellspacing="0" border="0" cellpadding="0">

    <tr><td colspan="2" align="center" class="qf-search-line" style="{$style['label_box']}; padding-top: 0px;" onMouseDown="dragStart(event, '{$form['id']}', 'savePosition', 0);">
        Status Info: {$stat_desc} [{$v_state}]
    </td></tr>

    <tr>
        <td align="right" class="qf-search-line" nowrap>Service Name</td>
        <td align="left" class="qf-search-line" style="font-weight:bold;">{$v_description}</td>
    </tr>
    <tr>
        <td align="right" class="qf-search-line">Hostname</td>
        <td align="left" class="qf-search-line">{$v_host_name}</td>
    </tr>

    <tr>
        <td align="right" class="qf-search-line" nowrap>Last Check</td>
        <td align="left" class="qf-search-line">{$v_last_check}</td>
    </tr>

    <tr>
        <td align="right" class="qf-search-line" nowrap>Status message</td>
        <td align="left" class="qf-search-line" nowrap>{$v_plugin_output}</td>
    </tr>

    <tr>
        <td align="right" class="qf-search-line" nowrap>Livestatus Server</td>
        <td align="left" class="qf-search-line" nowrap><a href="{$livestatusservers[$form['livestatusserver']][viewURL]}{$v_host_name}" title="View host on server" target="blank">{$form['livestatusserver']} ({$livestatusservers[$form['livestatusserver']][socketAddress]})</a></td>
    </tr>
    <tr id="ackrow">
        <td align="right" class="qf-search-line" nowrap>Acknowledged</td>
EOL;

    // dont show ack dialog if its in an OK state
    if ($v_state==0) {
    $html .= <<<EOL
        <td align="left" class="row-normal" 
            onMouseOver="this.className='row-highlight';" onMouseOut="this.className='row-normal';"
        >{$v_acknowledged}</td>
EOL;
    } else {
    $html .= <<<EOL
        <td align="left" class="row-normal"
            style="text-decoration:underline;cursor:pointer;color:#6B7DD1;"
            title="{$ack_tip}"
            onMouseOver="this.className='row-highlight';" onMouseOut="this.className='row-normal';"
            onClick="{$ackjs}"
        >{$v_acknowledged}</td>
EOL;
    }

    $html .= <<<EOL
    </tr>
    <tr id="livestatus_ackform" style="display:none">
        <td align="right" class="qf-search-line" nowrap>Acknowledge</td>
        <td align="left" class="qf-search-line">
          <form id="naginfo_ack_form" onSubmit="if (el('ackcomment').value == '') {el('ackcomment').value = 'ACK'};xajax_window_submit('{$file}', 'cmd=>ACKNOWLEDGE_{$cmdtype}_PROBLEM;{$v_host_name};{$v_description};'+Number(el('acksticky').checked)+';'+Number(el('acknotify').checked)+';'+Number(el('ackpersistant').checked)+';{$_SESSION['ona']['auth']['user']['username']};ONA:'+el('ackcomment').value+',serverinstance=>{$form['livestatusserver']},tooltipid=>{$form['id']}', 'livestatus_cmd');return false;">
          <label for="ackpersistant">&nbsp; &nbsp; Persist</label>
          <input id="ackpersistant" type="checkbox" name="ackpersistant" >
          <label for="acksticky">Sticky</label>
          <input id="acksticky" type="checkbox" name="acksticky" checked>
          <label for="acknotify">Notify</label>
          <input id="acknotify" type="checkbox" name="acknotify" checked><br>
          <label for="ackcomment">Comment</label>
          <input id="ackcomment" type="input" name="ackcomment" value="ACK" class="edit" onclick="el('ackcomment').value='';">
          <input id="acksend" type="image" src="{$images}/silk/bullet_go.png" title="Send ACK" class="act" style="vertical-align: middle" name="acksend"><br><br>
          </form>
        </td>
    </tr>

    <tr>
        <td align="right" class="qf-search-line" nowrap>Active checks enabled</td>
        <td align="left" class="row-normal"
            style="text-decoration:underline;cursor:pointer;color:#6B7DD1;"
            title="{$actchk_tip}"
            onMouseOver="this.className='row-highlight';" onMouseOut="this.className='row-normal';"
            onClick="xajax_window_submit('{$file}', 'cmd=>{$actchk_cmd},serverinstance=>{$form['livestatusserver']},tooltipid=>{$form['id']}', 'livestatus_cmd');"
        >{$v_active_checks_enabled}</td>
    </tr>
    <tr>
        <td align="right" class="qf-search-line" nowrap>Notifications enabled</td>
        <td align="left" class="row-normal"
            style="text-decoration:underline;cursor:pointer;color:#6B7DD1;"
            title="{$notify_tip}"
            onMouseOver="this.className='row-highlight';" onMouseOut="this.className='row-normal';"
            onClick="xajax_window_submit('{$file}', 'cmd=>{$notify_cmd},serverinstance=>{$form['livestatusserver']},tooltipid=>{$form['id']}', 'livestatus_cmd');"
        >{$v_notifications_enabled}</td>
    </tr>
    <tr>
        <td align="right" class="qf-search-line" nowrap>Comments</td>
        <td align="left" class="qf-search-line" nowrap>{$commentTxt}</td>
    </tr>

    </table>

EOL;

    $response->addScript("el('{$form['id']}').style.visibility = 'hidden';");
    $response->addAssign($form['id'], "innerHTML", $html);
    $response->addScript("wwTT_position('{$form['id']}'); el('{$form['id']}').style.visibility = 'visible';");
    if ($js) { $response->addScript($js); }
    return($response->getXML());
}






function ws_livestatus_cmd($window_name, $form) {
    global $livestatusservers, $conf, $self, $base;
    $html = $js = '';

    $response = new xajaxResponse();

    // Get info about this file name
    $onainstalldir = dirname($base);
    $file = str_replace($onainstalldir.'/www', '', __FILE__);
    $thispath = dirname($file);

    // If an array in a string was provided, build the array and store it in $form
    $form = parse_options_string($form);

    $cmdtime = time();

    // Define query for livestatus
    $cmd = "COMMAND [{$cmdtime}] {$form['cmd']}\n";

    // Connect and run query
    list($failstat,$errstring)=connectLiveSocket($livestatusservers[$form['serverinstance']]);
    if (!$failstat) {
        $service = commandLivestatus($cmd);
        closeLiveSocket();
    } else {
        $html .= "ERROR: $errstring";
    }

    // Log some info
    $self['error'] = "livestatus_cmd: Sent cmd {$form['cmd']}";
    printmsg($self['error'], 0);

    // Adding this here until I get it added to the core javascript code
    $jssleep = <<<EOL
function sleep(milliseconds) {
  var start = new Date().getTime();
  for (var i = 0; i < 1e7; i++) {
    if ((new Date().getTime() - start) > milliseconds){
      break;
    }
  }
}
EOL;

    // remove the tooltip.. kinda cruddy but its quick and simple then 
    // reload the main nagios info panel
    $response->addScript("{$jssleep} el('{$form['tooltipid']}').style.display='none'; sleep(500); xajax_window_submit('{$file}', xajax.getFormValues('naginfo_form'), 'display_nagiosstats');");
    return($response->getXML());
}



?>
