<?php
$plugin['name'] = 'wlk_defensio';
$plugin['version'] = '1';
$plugin['author'] = 'Walker Hamilton';
$plugin['author_uri'] = 'http://www.walkerhamilton.com';
$plugin['description'] = 'A comment spam prevention plugin using the Defensio service.';
$plugin['type'] = 1;

@include_once('zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---
<h1>wlk_defensio</h1>

<h2>Installation</h2>

<ol>
	<li>Since you can read this help, you have installed the plugin to txp.
		<ul><li>Did you activate it?</li></ul>
	</li>
	<li>Go to "Defensio.com":http://defensio.com and register for an account...I'll wait</li>
	<li>Go to the manage key page in your account, and register for a key for the URL you textpattern install can be found at.</li>
	<li>Once you have your API key, within the Textpattern interface, go to Extensions->Defensio and add your API key to the options form.</li>
</ol>

<h2>Options</h2>

<p>If you Set "Auto-mark as spam?" to "Off", if Defensio tells your Textpattern install that a comment is spam, it will be weighted moderate and Defensio will not set the offending comment to be hidden.</p>

<p>Spaminess is defensios 0 to 1 number of the probability that a comment is spam. Usually, everything above .8 (on a well-taught Defensio account) is spam.</p>

<h2>Usage</h2>

<p>Because Defensio is a learning system, make sure you go to Extensions->Defensio from time to time and correct any incorrectly marked comments.</p>

<h2>Uninstall</h2>

<p>Go to the Extensions->Defensio tab and the click the link under the options form that says "click here to uninstall". Once you do this, do not revisit the Extensions->Defensio tab as it will automatically reinstall. Instead, go to Admin->Plugins and remove or deactivate the wlk_defensio plugin.</p>


# --- END PLUGIN HELP ---
<?php
}

# --- BEGIN PLUGIN CODE ---

register_callback('wlk_defensio','comment.save');

if (@txpinterface == 'admin')
{
	register_tab("extensions", "wlk_defensio_admin", "Defensio");
	
	add_privs('wlk_defensio_admin', '1,2');
	register_callback("wlk_defensio_admin", "wlk_defensio_admin");
	
	register_callback('wlk_defensio_announce', 'article', 'publish');
}

class WlkDefensioReturnParser {
    
    var $arrOutput = array();
    var $resParser;
    var $strXmlData;
    var $defensioArray;
    
    function __construct($xml) {
    	$returner = array();
    	$r_r = $this->parse($xml);
    	foreach($r_r['children'] as $arr)
    	{
    		if(isset($arr['tagData'])) { $returner[$arr['name']] = $arr['tagData']; }
    	}
    	$this->defensioArray = $returner;
    }
    
    function WlkDefensioReturnParser($xml) {
    	return $this->__construct($xml);
    }
    
	function parse($strInputXML) {
		$this->resParser = xml_parser_create ();
		xml_set_object($this->resParser,$this);
		xml_set_element_handler($this->resParser, "tagOpen", "tagClosed");
		
		xml_set_character_data_handler($this->resParser, "tagData");
		
		$this->strXmlData = xml_parse($this->resParser,$strInputXML );
		if(!$this->strXmlData) {
		   die(sprintf("XML error: %s at line %d",
		xml_error_string(xml_get_error_code($this->resParser)),
		xml_get_current_line_number($this->resParser)));
		}
						
		xml_parser_free($this->resParser);
		
		return $this->arrOutput[0];
	}

	function tagOpen($parser, $name, $attrs) {
		if(!empty($name) && !empty($attrs)) {
			$tag=array("name"=>strtolower($name),"attrs"=>$attrs);
		} else if(!empty($name) && empty($attrs)) {
			$tag=array("name"=>strtolower($name));
		} else if(empty($name) && !empty($attrs)) {
			$tag=array("attrs"=>$attrs);
		}
		array_push($this->arrOutput,$tag);
	}

	function tagData($parser, $tagData) {
		if(trim($tagData)) {
			if(isset($this->arrOutput[count($this->arrOutput)-1]['tagData'])) {
				$this->arrOutput[count($this->arrOutput)-1]['tagData'] .= $tagData;
			} else {
				$this->arrOutput[count($this->arrOutput)-1]['tagData'] = $tagData;
			}
		}
	}
    
	function tagClosed($parser, $name) {
		$this->arrOutput[count($this->arrOutput)-2]['children'][] = $this->arrOutput[count($this->arrOutput)-1];
		array_pop($this->arrOutput);
	}

}

class WlkDefensio {
	var $_properties = array(
			'urls' => array(
				'v-key' => 'http://api.defensio.com/blog/1.1/validate-key/',
				'report-fn' => 'http://api.defensio.com/blog/1.1/report-false-negatives/',
				'audit-comm' => 'http://api.defensio.com/blog/1.1/audit-comment/',
				'report-fp' => 'http://api.defensio.com/blog/1.1/report-false-positives/',
				'stats' => 'http://api.defensio.com/blog/1.1/get-stats/',
				'ann-art' => 'http://api.defensio.com/blog/1.1/announce-article/'
			),
			'apikey' => '',
			'site-owner' => ''
		);

	function __construct()
	{
		if(isset($_GET['uninstall'])) {
			$this->db_add(true);
			echo '<div style="width:400px;margin-left:auto;margin-right:auto;"><h1>Make sure you do this next</h1>
			
				<p>If you revisit the Defensio tab here under extensions without disabling or uninstalling the wlk_defensio plugin, Defensio will be reinstalled on your system. To avoid this, click the "yes" on the Defensio row in the Admin->Plugins tab to disable it or click the "x" to uninstall it altogether.</p></div>
			
			';
		} else {
			//check if it's got an api key installed
			$checkInstall = fetch('name','txp_prefs','name','wlk_defensio_apikey');

			if(empty($checkInstall)) { 
				$this->db_add();
			} else  {
				$this->_properties['apikey'] = fetch('val','txp_prefs','name','wlk_defensio_apikey');
				if(isset($_POST['wlk_defensio_apikey'])) {
					safe_query('UPDATE '.safe_pfx('txp_prefs').' SET val="'.addslashes($_POST['wlk_defensio_apikey']).'" WHERE name="wlk_defensio_apikey"');
					safe_query('UPDATE '.safe_pfx('txp_prefs').' SET val="'.addslashes($_POST['wlk_defensio_auto_mark']).'" WHERE name="wlk_defensio_auto_mark"');
					safe_query('UPDATE '.safe_pfx('txp_prefs').' SET val="'.addslashes($_POST['wlk_defensio_limit']).'" WHERE name="wlk_defensio_limit"');
				
					pagetop("Defensio", "Your Defensio options have been updated.");
					$this->show_api_form();
					exit();
				} else {
					if (@txpinterface == 'admin')
					{
						if(isset($_GET['reportfalsepos'])) {
							$this->report_false_positive($_GET['reportfalsepos']);
						} else if(isset($_GET['reportfalseneg'])) {
							$this->report_false_negative($_GET['reportfalseneg']);
						} else {
							pagetop("Defensio");
						}
					
						$this->show_api_form();
						echo '<td id="article-main" colspan="2">&nbsp;';
						$this->admin_interface();
						echo '</td></tr></table>';
						exit();
					}
				}
			}
		}
	}
	
	function WlkDefensio()
	{
		$this->__construct();
	}

	function doCurl($api_point, $post_vars, $turn_off_user_ip=null)
	{
		$this->_properties['apikey'] = fetch('val','txp_prefs','name','wlk_defensio_apikey');
		$this->_properties['site-owner'] = 'http://'.fetch('val','txp_prefs','name','siteurl');

		$post_vars['owner-url'] = $this->_properties['site-owner'];
		
		if(!$turn_off_user_ip) {
			$post_vars['user-ip'] = ($_SERVER['REMOTE_ADDR'] != getenv('SERVER_ADDR')) ? $_SERVER['REMOTE_ADDR'] : getenv('HTTP_X_FORWARDED_FOR');
		}
		
		$curl = curl_init($this->_properties['urls'][$api_point].$this->_properties['apikey'].'.xml');
		
		curl_setopt($curl, CURLOPT_USERAGENT, 'Textpattern/0.1 (+http://walkerhamilton.com/defensio)');
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post_vars);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		
		$response = curl_exec($curl);
		curl_close($curl);
		
		return $response;
	}
	
	function announce()
	{
		global $permlink_mode;
		global $thisarticle;
		if($thisarticle['annotate']==1)
		{
			$defensio_posted = date('Y\/m\/d', $thisarticle['posted']);
			$defensio_permlink = permlinkurl($thisarticle);
			
			$form_array = getComment();
			
			$author = fetch('RealName','txp_users','name',$thisarticle['authorid']);
			$author_email = fetch('email','txp_users','name',$thisarticle['authorid']);

			$defensio_version = array('permalink'=>$defensio_permlink, 'article-content'=>$thisarticle['excerpt'].' '.$thisarticle['body'], 'article-title'=>$thisarticle['title'], 'article-author'=>$author, 'article-author-email'=>$author_email);
			
			$this->doCurl('ann-art', $defensio_version);
		}
	}
	
	function audit_comment()
	{
		global $permlink_mode;
		global $thisarticle;
		
		$defensio_posted = date('Y\/m\/d', $thisarticle['posted']);
		$defensio_permlink = permlinkurl($thisarticle);
		
		$form_array = getComment();
		/*
			'parentid',
			'name',
			'email',
			'web',
			'message',
			'backpage',
			'remember'
		*/
		
		$qShowStatus = "SHOW TABLE STATUS LIKE '".safe_pfx('txp_discuss')."'";
		$qShowStatusResult = safe_query($qShowStatus);
		
		$row = mysql_fetch_assoc($qShowStatusResult);
		
		$next_increment = $row['Auto_increment'];
		
		if(is_numeric($next_increment)) {
			$defensio_version = array('article-date'=>$defensio_posted, 'comment-author'=>$form_array['name'], 'comment-type'=>'comment', 'comment-author-email'=>$form_array['email'], 'comment-author-url'=>$form_array['web'], 'user-logged-in'=>false, 'permalink'=>$defensio_permlink, 'comment-content'=>$form_array['message']);

			$response = $this->doCurl('audit-comm', $defensio_version);

			$xml_p = new WlkDefensioReturnParser($response);

			$evaluator =& get_comment_evaluator();

			$theauto = fetch('val','txp_prefs','name','wlk_defensio_auto_mark');
			$thelimiter = fetch('val','txp_prefs','name','wlk_defensio_limit');

			if($xml_p->defensioArray['spam']==true || $xml_p->defensioArray['spam']=='true' || $xml_p->defensioArray['spam']==1) {
				$spam = 1;
			} else {
				$spam = 0;
			}

			include_once txpath.'/lib/classTextile.php';
			$textile = new Textile();

			$textiled_message = $textile->TextileThis($form_array['message']);

			safe_insert('txp_discuss_defensio', "discuss_id='".$next_increment."', defensio_id='".addslashes($xml_p->defensioArray['signature'])."', name='".addslashes($form_array['name'])."', email='".addslashes($form_array['email'])."', message='".addslashes($textiled_message)."', spaminess='".addslashes($xml_p->defensioArray['spaminess'])."', spam='".$spam."'");

			if($theauto==1) {
				if ($spam==1 || $xml_p->defensioArray['spaminess']>$thelimiter || $xml_p->defensioArray['spaminess']==$thelimiter)
					$evaluator -> add_estimate(SPAM, $xml_p->defensioArray['spaminess']);
				if ($xml_p->defensioArray['spaminess']>0.4)
					$evaluator -> add_estimate(MODERATE, $xml_p->defensioArray['spaminess']);
				else
					$evaluator -> add_estimate(VISIBLE, $xml_p->defensioArray['spaminess']);
			} else {
				$evaluator -> add_estimate(VISIBLE, $xml_p->defensioArray['spaminess']);
			}
		}
	}
	
	function report_false_negative($defensio_id)
	{
		// get defensio row & check to make sure we've got a discuss_id
		$discuss_row = safe_row('discuss_id, report_false_positive', 'txp_discuss_defensio', 'defensio_id="'.addslashes($defensio_id).'"');
		
		//let defensio know it really is spam
		$defensio_sig = array('signatures'=>$defensio_id);
		$response = $this->doCurl('report-fn', $defensio_sig);
		
		$xml_p = new WlkDefensioReturnParser($response);
		
		// if it succeeded, do the below
		if($xml_p->defensioArray['status']=='success') {
			// if it was reported as a false_negative before, don't set it as a false positive
			if($discuss_row['report_false_positive']==0) {
				//set discuss_defensio row that it was reported as a false_negative
				safe_update('txp_discuss_defensio', 'report_false_negative=1', 'defensio_id="'.addslashes($defensio_id).'"');
			}
			
			// get auto-mark setting
			$theauto = fetch('val','txp_prefs','name','wlk_defensio_auto_mark');
			
			if($theauto==1) {
				// set the comment to visible=-1 on the discuss table
				safe_update('txp_discuss', 'visible="-1"', 'discussid="'.addslashes($discuss_row['discuss_id']).'"');
			}
			
			pagetop("Defensio", "The false negative was reported to Defensio.");
		} else {
			// else, let them know the report failed.
			pagetop("Defensio", "Sorry, but we failed to notify Defensio of the false negative. Please try again.");
		}
	}
	
	function report_false_positive($defensio_id)
	{
		// get defensio row & check to make sure we've got a discuss_id
		$discuss_row = safe_row('discuss_id, report_false_negative', 'txp_discuss_defensio', 'defensio_id="'.addslashes($defensio_id).'"');
		
		//let defensio know it wasn't really spam
		$defensio_sig = array('signatures'=>$defensio_id);
		$response = $this->doCurl('report-fp', $defensio_sig);
		
		$xml_p = new WlkDefensioReturnParser($response);
		// if it succeeded, do the below
		if($xml_p->defensioArray['status']=='success') {
			// if it was reported as a false_negative before, don't set it as a false positive
			if($discuss_row['report_false_negative']==0) {
				//set discuss_defensio row that it was reported as a false_positive
				safe_update('txp_discuss_defensio', 'report_false_positive=1', 'defensio_id="'.addslashes($defensio_id).'"');
			}
			
			// get auto-mark setting
			$theauto = fetch('val','txp_prefs','name','wlk_defensio_auto_mark');
			
			if($theauto==1) {
				// set the comment to visible=1 on the discuss table
				safe_update('txp_discuss', 'visible="1"', 'discussid="'.addslashes($discuss_row['discuss_id']).'"');
			}
			
			pagetop("Defensio", "The false positive was reported to Defensio.");
		} else {
			// else, let them know the report failed.
			pagetop("Defensio", "Sorry, but we failed to notify Defensio of the false positive. Please try again.");
		}
	}
	
	function admin_interface($step=null)
	{
		global $prefs;
		
		$theids = safe_query('SELECT DISTINCT(`'.safe_pfx('txp_discuss_defensio').'`.`id`) FROM `'.safe_pfx('txp_discuss_defensio').'` LEFT JOIN `'.safe_pfx('txp_discuss').'` ON `'.safe_pfx('txp_discuss_defensio').'`.`discuss_id`=`'.safe_pfx('txp_discuss').'`.`discussid` WHERE `'.safe_pfx('txp_discuss').'`.`discussid` IS NULL');
		
		if(mysql_num_rows($theids)>0)
		{
			$ids = array();
			while($row = mysql_fetch_assoc($theids)) {
				$ids[] = $row['id'];
			}
			
			if(count($ids)>0) {
				$where_s = implode(', ', $ids);
				safe_query('DELETE FROM '.safe_pfx('txp_discuss_defensio').' WHERE '.safe_pfx('txp_discuss_defensio').'.`id` IN ('.$where_s.')');
			}
		}
		
		if(isset($_GET['page']) && is_numeric($_GET['page']))
		{
			$page = addslashes($_GET['page']-1);
			$thepage = $_GET['page'];
		} else {
			$page = 0;
			$thepage = 1;
		}
		
		$the_count_r = safe_query('SELECT COUNT(DISTINCT('.safe_pfx('txp_discuss_defensio').'.id)) AS count FROM '.safe_pfx('txp_discuss_defensio').' LEFT JOIN '.safe_pfx('txp_discuss').' ON '.safe_pfx('txp_discuss_defensio').'.discuss_id='.safe_pfx('txp_discuss').'.discussid LEFT JOIN '.safe_pfx('textpattern').' ON '.safe_pfx('textpattern').'.ID='.safe_pfx('txp_discuss').'.parentid');
		
		$the_count = mysql_fetch_assoc($the_count_r);
		$the_count = $the_count['count'];
		
		//Grab all defensio'd comments, paginate (most recent 20 first)
		$d_comments_r = safe_query('SELECT '.safe_pfx('textpattern').'.ID, '.safe_pfx('textpattern').'.Title, '.safe_pfx('txp_discuss_defensio').'.id, '.safe_pfx('txp_discuss_defensio').'.defensio_id, '.safe_pfx('txp_discuss_defensio').'.spaminess, '.safe_pfx('txp_discuss_defensio').'.spam, '.safe_pfx('txp_discuss_defensio').'.report_false_negative, '.safe_pfx('txp_discuss_defensio').'.report_false_positive, '.safe_pfx('txp_discuss').'.name, '.safe_pfx('txp_discuss').'.email, '.safe_pfx('txp_discuss').'.web, '.safe_pfx('txp_discuss').'.ip, '.safe_pfx('txp_discuss').'.message, '.safe_pfx('txp_discuss').'.visible FROM '.safe_pfx('txp_discuss_defensio').' LEFT JOIN '.safe_pfx('txp_discuss').' ON '.safe_pfx('txp_discuss_defensio').'.discuss_id='.safe_pfx('txp_discuss').'.discussid LEFT JOIN '.safe_pfx('textpattern').' ON '.safe_pfx('textpattern').'.ID='.safe_pfx('txp_discuss').'.parentid ORDER BY '.safe_pfx('txp_discuss').'.discussid DESC LIMIT '.$page.', 20');
		
		if(mysql_num_rows($d_comments_r)>0)
		{
			//Display them
			echo '
			<table cellpadding="0" cellspacing="0" border="0" id="list" align="center" width="90%">
				<tr>
					<th>Name</th>
					<th>Status</th>
					<th>Parent</th>
					<th class="defensio">Spam?</th>
					<th class="defensio">Spaminess</th>
				</tr>
			';
	
			while($comment = mysql_fetch_array($d_comments_r))
			{
				//print_r($comment);
				echo '
				<tr';
				if($comment[6]==1) {
					echo ' style="background:#ff7f50"';
				}
				if($comment[7]==1) {
					echo ' style="background:#eee8aa"';
				}
				echo '>
				';
				if(strpos($comment[10], 'http://')===false) {
					echo '<td><a href="http://'.$comment[10].'" title="'.$comment[10].'">'.$comment[8].'</a></td>';
				} else {
					echo '<td><a href="'.$comment[10].'" title="'.$comment[10].'">'.$comment[8].'</a></td>';
				}
					echo '<td>';
					if($comment[13]=='-1') {
						echo 'Hidden';
					} else {
						echo 'Visible';
					}
					echo '</td>
					<td><a href="?event=list&#38;step=list&#38;search_method=id&#38;crit='.$comment[0].'" title="View this Article">'.$comment[1].'</a></td>
					<td>';
					if($comment[5]==1) {
						echo '<a href="?event=wlk_defensio_admin';
						if(isset($_GET['page']))
						{
							echo '&#38;page='.$_GET['page'];
						}
						echo '&#38;reportfalsepos='.$comment[3].'" title=\'Switch to "No"?\'>Yes</a>';
					} else {
						echo '<a href="?event=wlk_defensio_admin';
						if(isset($_GET['page']))
						{
							echo '&#38;page='.$_GET['page'];
						}
						echo '&#38;reportfalseneg='.$comment[3].'" title=\'Switch to "Yes"?\'>No</a>';
					}
					echo '</td>
					<td title="Spaminess Rating (1==definite spam)">'.$comment[4].'</td>
				</tr>
				';
			}
			
			echo '
				<tr>
					<td colspan="3" style="padding-top:5px;text-align:right;border:none;">
						<span style="background:#ff7f50">&nbsp; &nbsp;</span> False Neg Reported &nbsp; | &nbsp; 
						<span style="background:#eee8aa">&nbsp; &nbsp;</span> False Pos Reported
					</td>
					<td colspan="2" style="padding-top:5px;text-align:right;border:none;">
				';
				if($thepage==1) {
					echo '<span class="navlink-disabled">&#8249;&#160;Prev</span>';
				} else {
					echo '<a href="?event=wlk_defensio_admin&#38;page='.($thepage-1).'" class="navlink">&#8249;&#160;Prev</a>';
				}
				
				echo ' &#160; ';
				
				if(($the_count/20)==$thepage || ($the_count/20)<$thepage) {
						echo '<span class="navlink-disabled">Next&#160;&#8250;</span>';
				} else {
					echo '<a href="?event=wlk_defensio_admin&#38;page='.($thepage+1).'" class="navlink">Next&#160;&#8250;</a>';
				}
			echo '
					</td>
				</tr>
			</table>
			';
		}
	}

	// -------------------------------------------------------------
	// --- This one is for updating the db
	// -------------------------------------------------------------
	function db_add($unInstall = null) {
		if($unInstall) {
			safe_delete('txp_prefs',"name = 'wlk_defensio_apikey'");
			safe_delete('txp_prefs',"name = 'wlk_defensio_limit'");
			safe_delete('txp_prefs',"name = 'wlk_defensio_auto_mark'");
			safe_query('DROP TABLE `'.safe_pfx('txp_discuss_defensio').'`');
			pagetop('Defensio', 'Defensio has been removed from your database.');
		} else {
			safe_insert('txp_prefs',"prefs_id = '1',name = 'wlk_defensio_apikey',val = '',type = '1',event = 'wlk_defensio',html = 'text_input',position = '10'");
			safe_insert('txp_prefs',"prefs_id = '1',name = 'wlk_defensio_limit',val = '0.8',type = '1',event = 'wlk_defensio',html = 'text_input',position = '20'");
			safe_insert('txp_prefs',"prefs_id = '1',name = 'wlk_defensio_auto_mark',val = '1',type = '1',event = 'wlk_defensio',html = 'yesnoradio',position = '30'");
			safe_query("CREATE TABLE ".safe_pfx('txp_discuss_defensio')." (
			  `id` int(11) NOT NULL auto_increment,
			  `discuss_id` int(6) unsigned zerofill NOT NULL default '000000',
			  `name` varchar(255) NOT NULL default '',
			  `email` varchar(50) NOT NULL default '',
			  `message` text NOT NULL,
			  `defensio_id` varchar(255) NOT NULL default '',
			  `spaminess` float NOT NULL default '0',
			  `report_false_negative` tinyint(1) NOT NULL default '0',
			  `report_false_positive` tinyint(1) NOT NULL default '0',
			  `spam` tinyint(1) NOT NULL default '0',
			  PRIMARY KEY  (`id`)
			) ENGINE=MyISAM");
			
			$this->__construct();
			//return 'Prefs saved to db';
		}
	}
	
	function show_api_form($api_key=null) {
		$thekey = fetch('val','txp_prefs','name','wlk_defensio_apikey');
		$theauto = fetch('val','txp_prefs','name','wlk_defensio_auto_mark');
		$thelimiter = fetch('val','txp_prefs','name','wlk_defensio_limit');
		$options = array('0'=>'0', '0.1'=>'0.1', '0.2'=>'0.2', '0.3'=>'0.3', '0.4'=>'0.4', '0.5'=>'0.5', '0.6'=>'0.6', '0.7'=>'0.7', '0.8'=>'0.8', '0.9'=>'0.9', '1'=>'1');
		
		echo "<table cellpadding=\"3\" cellspacing=\"0\" border=\"0\" id=\"edit\" align=\"center\"><tr><td id=\"article-col-1\">";
		echo form(
			graf("<fieldset>".tag("Options", "legend")."Enter your API key here:".
				fInput("hidden", "wlk_defensio_admin", 'wlk_defensio_admin').
				fInput("text", "wlk_defensio_apikey", $thekey).
				"<br /><br />Auto-mark as spam?<br />".
				onoffRadio("wlk_defensio_auto_mark", $theauto).
				"<br /><br />&hellip;if spaminess &gt;&#61; ".
				selectInput("wlk_defensio_limit", $options, $thelimiter).
				"<br /><br />".
				fInput("submit", "save", "Save", "smallerbox").
				eInput("wlk_defensio_admin").
				//.sInput("step_a")
				"</fieldset>"
			)
		);
		echo '</div>
			<p><a href="?event=wlk_defensio_admin&#38;uninstall=true">click here</a> to uninstall<p>
		</td>';
	}
}

function wlk_defensio()
{
	$wlk_def = new WlkDefensio();
	$wlk_def->audit_comment();
}

function wlk_defensio_admin()
{
	$wlk_def = new WlkDefensio();
}

function wlk_defensio_announce()
{
	$wlk_def = new WlkDefensio();
	$wlk_def->announce();
}
# --- END PLUGIN CODE ---

?>