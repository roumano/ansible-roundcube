<?php
class radius_otp extends rcube_plugin
{
    // registered tasks for this plugin.
    public $task = 'login|logout';

    function init()
    {
        // this conditional is to allow internal traffic (from revint1/2) to bypass OTP plugin entirely. Tests that traffic
        // doesn't come from internal reverses
        //if (!($_SERVER['HTTP_X_ROUNDCUBE_OTP'] == '0f51f28a5f714426b5001158551b6b7c' && ( $_SERVER['REMOTE_ADDR'] == '172.26.11.11' || $_SERVER['REMOTE_ADDR'] == '172.26.11.12')))
        if (true)
        {
            $rcmail = rcmail::get_instance();

            // check whether the "global_config" plugin is available,
            // otherwise load the config manually.
            $plugins = $rcmail->config->get('plugins');

            $plugins = array_flip($plugins);
            if (!isset($plugins['global_config']))
            {
                $this->load_config();
            }
            $this->otp_debug = $rcmail->config->get('otp_debug');

            // Add OTP field on login page
            $this->add_hook('template_object_loginform', array($this,'radius_otp_loginform'));

            // is the request from webmail2.embl.fr ?
            if ($_SERVER['SERVER_NAME'] == 'roundcube.embl.fr')
            {
                // register hook for fortinet
                $this->add_hook('authenticate', array($this, 'authenticate_fortinet'));
            } else {
                // register hook for actividentity
		            // Temporary modificaiton : only use fortinet
                $this->add_hook('authenticate', array($this, 'authenticate_fortinet'));
                //$this->add_hook('authenticate', array($this, 'authenticate_actividentity'));
            }
        }
    }

    function radius_otp_loginform($content)
    {
        // import javascript client code.
        $this->include_script('radius_otp.js');

        return $content;
    }

    function authenticate_fortinet($args)
    {
        $this->authenticate_args = $args;
        if ($this->otp_debug)
        {
            rcmail::write_log('otp', 'OTP Debug: Begin function authenticate_fortinet');
        }

        $user = $args['user'];
        $code = rcube_utils::get_input_value('_code', rcube_utils::INPUT_POST);
        $user_remote_ip = rcube_utils::remote_ip();
        rcmail::write_log('otp', 'OTP Authentification: OTP begin for '.$user.' from '.$user_remote_ip.' with code '.$code);
        if (!self::radius_otp_auth($user, $code, "fortinet",$this->otp_debug))
        {
            rcmail::write_log('otp', 'OTP Error: Otp failed for '.$user.' from '.$user_remote_ip.' Authentication failed');
            $args['abort'] = true;
        }

        return $args;
    }

    function authenticate_actividentity($args)
    {
        $this->authenticate_args = $args;

        $user = $args['user'];
        $code = rcube_utils::get_input_value('_code', rcube_utils::INPUT_POST);
        $user_remote_ip = rcube_utils::remote_ip();

        if (!self::radius_otp_auth($user, $code, "actividentity",$this->otp_debug))
        {
            rcmail::write_log('otp', 'OTP Error: Otp failed for '.$user.' from '.$user_remote_ip.' Authentication failed');
            $args['abort'] = true;
        }

        return $args;
    }
    function radius_otp_auth($user, $code, $kind, $debug)
    {
        if ($debug)
        {
            rcmail::write_log('otp', 'OTP Debug: Begin function radius_otp_auth');
        }
        require("/usr/share/php-radius/radius_authentication.inc.php");

        $retval = RADIUS_AUTHENTICATION($user, $code, $kind, $debug);
        switch ($retval)
        {
            case 2:
                /* 2 -> Access-Accept */
                #return TRUE;
                $logged_in = 1;
                break;
            case 3:
                /* 3 -> Access-Reject */
                #echo "login incorrect";
                $logged_in = 0;
                break;
            default:
                #echo "temporally failure or other error";
                write_log("errors", "temporally failure or other error");
                break;
        }
        return $logged_in;
    }
}
