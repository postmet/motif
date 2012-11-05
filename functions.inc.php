<?php

global $amp_conf;

class motif_conf {

	//Tell freepbx which files we want to 'control'
	function get_filename() {
        $files = array(
			'motif.conf',
            'xmpp.conf',
			'rtp.conf',
            'extensions_additional.conf'
        );
        return $files;
	}

	//This function is called for every file defined in 'get_filename()' function above
	function generateConf($file) {
        global $version,$amp_conf,$astman;

		//Create all custom files
        if(!file_exists($amp_conf['ASTETCDIR'] . '/motif_custom.conf')) {
            touch($amp_conf['ASTETCDIR'] . '/motif_custom.conf');
        }

        if(!file_exists($amp_conf['ASTETCDIR'] . '/xmpp_custom.conf')) {
            touch($amp_conf['ASTETCDIR'] . '/xmpp_custom.conf');
        }

		//Backup all old xmpp & motif.conf files
        if(file_exists($amp_conf['ASTETCDIR'] . '/xmpp.conf') && !file_exists($amp_conf['ASTETCDIR'] . '/xmpp.conf.bak')) {
            copy($amp_conf['ASTETCDIR'] . '/xmpp.conf', $amp_conf['ASTETCDIR'] . '/xmpp.conf.bak');
        }

        if(file_exists($amp_conf['ASTETCDIR'] . '/motif.conf') && !file_exists($amp_conf['ASTETCDIR'] . '/motif.conf.bak')) {
            copy($amp_conf['ASTETCDIR'] . '/motif.conf', $amp_conf['ASTETCDIR'] . '/motif.conf.bak');
        }

		//Disable gtalk and jabber
		if(file_exists($amp_conf['ASTETCDIR'] . '/jabber.conf') && !file_exists($amp_conf['ASTETCDIR'] . '/jabber.conf.old')) {
            rename($amp_conf['ASTETCDIR'] . '/jabber.conf', $amp_conf['ASTETCDIR'] . '/jabber.conf.old');
        }

		if($astman->mod_loaded('jabber')) {
			$astman->send_request('Command', array('Command' => 'module unload res_jabber.so'));
		}

        if(file_exists($amp_conf['ASTETCDIR'] . '/gtalk.conf') && !file_exists($amp_conf['ASTETCDIR'] . '/gtalk.conf.old')) {
            rename($amp_conf['ASTETCDIR'] . '/gtalk.conf', $amp_conf['ASTETCDIR'] . '/gtalk.conf.old');
        }

		if($astman->mod_loaded('gtalk')) {
			$astman->send_request('Command', array('Command' => 'module unload chan_gtalk.so'));
		}

		//Setup specific file matching
        switch ($file) {
            case 'motif.conf':
                return $this->generate_motif_conf($version);
                break;
            case 'xmpp.conf':
                return $this->generate_xmpp_conf($version);
                break;
			case 'rtp.conf':
            	return $this->generate_rtp_conf($version);
            	break;
            case 'extensions_additional.conf':
                return $this->generate_extensions_conf($version);
                break;
        }
    }

    function generate_motif_conf($ast_version) {
        global $astman;

		$sql = 'SELECT * FROM `motif`';
		$accounts = sql($sql, 'getAll', DB_FETCHMODE_ASSOC);

		//Clear output for motif file
        $output = '';
		foreach($accounts as $list) {
			$context = str_replace('@','',str_replace('.','',$list['username'])); //Remove special characters for use in contexts. There still might be a char limit though
			$output .= "[g".$context."]\n"; //Add contexts for each 'line'
			$output .= "context=im-".$context."\n";
			$output .= "disallow=all\n";
			$output .= "allow=ulaw\n";
			$output .= "connection=g".$context."\n";
		}
		return $output;
	}

	function generate_xmpp_conf($ast_version) {
		global $astman,$db;

		$sql = 'SELECT * FROM `motif`';
		$accounts = sql($sql, 'getAll', DB_FETCHMODE_ASSOC);

		$output = "[general]\n";
		foreach($accounts as $list) {
			$context = str_replace('@','',str_replace('.','',$list['username'])); //Remove special characters for use in contexts. There still might be a char limit though

			$output .= "[g".$context."]\n";
			$output .= "type=client\n";
			$output .= "serverhost=talk.google.com\n";

			$output .= "username=".$list['username']."\n";
			$output .= "secret=".$list['password']."\n";

			$output .= "priority=1\n";
			$output .= "port=5222\n";
			$output .= "usetls=yes\n";
			$output .= "usesasl=yes\n";
			$output .= "status=available\n";
			$output .= "statusmessage=\"I am available\"\n";
			$output .= "timeout=5\n";
		}


		return $output;
	}

	function generate_rtp_conf($ast_version) {
		global $astman;

		//RTP settings are predefined here. This will upset some people
		$output = "[general]\n";
		$output .= ";rtp settings are defined in the chan_motif freepbx module\n";
		$output .= "rtpstart=10000\n"; //Normal Starting Point
		$output .= "rtpend=20000\n"; //Normal Ending Point
		$output .= "icesupport=yes\n"; //icesupport is required for googlevoice: https://wiki.asterisk.org/wiki/display/AST/Calling+using+Google

		return $output;
	}

	function generate_extensions_conf($ast_version) {
        global $ext;

		$sql = 'SELECT * FROM `motif`';
		$accounts = sql($sql, 'getAll', DB_FETCHMODE_ASSOC);

		foreach($accounts as $list) {
			$context = str_replace('@','',str_replace('.','',$list['username'])); //Remove special characters for use in contexts. There still might be a char limit though
			$incontext = "im-".$context;
			$address = 's'; //Joshua Colp @ Digium: 'It will only accept from the s context'

			$ext->add($incontext, $address, '1', new ext_noop('Receiving GoogleVoice on DID: '.$list['phonenum']));

			$ext->add($incontext, $address, '', new ext_noop('${EXTEN}'));

			$ext->add($incontext, $address, '', new ext_setvar('CALLERID(name)', '${CUT(CALLERID(name),@,1)}'));
	        $ext->add($incontext, $address, '', new ext_gotoif('$["${CALLERID(name):0:2}" != "+1"]', 'nextstop'));
	        $ext->add($incontext, $address, '', new ext_setvar('CALLERID(name)', '${CALLERID(name):2}'));
	        $ext->add($incontext, $address, 'nextstop', new ext_gotoif('$["${CALLERID(name):0:1}" != "+"]', 'notrim'));
	        $ext->add($incontext, $address, '', new ext_setvar('CALLERID(name)', '${CALLERID(name):1}'));
	        $ext->add($incontext, $address, 'notrim', new ext_setvar('CALLERID(number)', '${CALLERID(name)}'));

			$ext->add($incontext, $address, '', new ext_wait('1'));
	        $ext->add($incontext, $address, '', new ext_answer(''));
	        $ext->add($incontext, $address, '', new ext_senddtmf('1'));

			$ext->add($incontext, $address, '', new ext_goto('1', $list['phonenum'], 'from-trunk'));

			$ext->add($incontext, 'h', '', new ext_hangup(''));
		}
		return $ext->generateConf();
	}
}
