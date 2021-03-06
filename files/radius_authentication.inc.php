<?
    //
    // $Id: radius_authentication.inc,v 1.3 2002/01/23 23:21:20 mavetju Exp $
    //
    // Roberto Lumbreras <rover@debian.org> Tue, 23 Mar 2004 00:34:01 +0100
    //   select fixes, error checks, more than one config file
    //
    // radius authentication v1.0 by Edwin Groothuis (edwin@mavetju.org)
    //
    // If you didn't get this file via http://www.mavetju.org, please
    // check for the availability of newer versions.
    //
    // See LICENSE for distribution issues. If this file isn't in
    // the distribution, please inform me about it.
    //
    // If you want to use this script, fill in the configuration in
    // radius_authentication.conf and call the function
    // RADIUS_AUTHENTICATION() with the username and password
    // provided by the user. If it returns a 2, the authentication
    // was successfull!

    // If you want to use this, make sure that you have raw sockets
    // enabled during compile-time: "./configure --enable-sockets".

function init_radiusconfig(&$server,&$port,&$sharedsecret,&$suffix, $kind)
{
    global $radius_server;
    if (is_file("/etc/php-radius/server-" . $kind . ".conf"))
    {
        $filename = "/etc/php-radius/server-" . $kind . ".conf";
    } elseif (is_file("radius_authentication.conf"))
    {
        $filename="radius_authentication.conf";
    } elseif (isset($radius_server) && is_file("/etc/php-radius/server-$radius_server.conf"))
    {
        $filename="/etc/php-radius/server-$radius_server.conf";
    } elseif (is_file("/etc/php-radius/server.conf"))
    {
        $filename="/etc/php-radius/server.conf";
    } else {
        rcmail::write_log('otp', 'OTP Error: OTP filename '.$filename.' not found');
        echo "Couldn't find any config file, exiting";
        exit(0);
    }
    if ($debug)
    {
        rcmail::write_log('otp', 'OTP Debug: OTP filename '.$filename);
    }
    $file=fopen($filename,"r");
    if ($file==0)
    {
        echo "Couldn't open $filename, exiting";
        exit(0);
    }
    while (!feof($file))
    {
        $s=fgets($file,1024);
        $s=chop($s);
        if ($s[0]=="#")
            continue;
        if (strlen($s)==0)
            continue;
        if (preg_match("/^([a-zA-Z]+) (.*)$/",$s,$a))
        {
            if ($a[1]=="port")
            {
                $port=$a[2];
                continue;
            }
            if ($a[1]=="server")
            {
                $server=$a[2];
                continue;
            }
            if ($a[1]=="secret")
            {
                $sharedsecret=$a[2];
                continue;
            }
            if ($a[1]=="suffix")
            {
                $suffix=$a[2];
                if ($suffix=="\"\"")
                {
                    $suffix="";
                }
                continue;
            }
        }
        echo "Unknown config-file option: $a[1] ($s)\n";
        exit(0);
    }
    fclose($file);
}

function RADIUS_AUTHENTICATION($username,$password,$kind,$debug)
{
    $radiushost="";
    $sharedsecret="";
    $suffix="";

    init_radiusconfig($radiushost,$radiusport,$sharedsecret,$suffix, $kind);
    if ($debug)
    {
        rcmail::write_log('otp', 'OTP Debug: radiushost '.$radiushost.' radiusport '.$radiusport.' suffix '.$suffix.' kind '.$kind);
    }

    // check your /etc/services. Some radius servers
    // listen on port 1812, some on 1645.
    if ($radiusport==0)
        $radiusport=getservbyname("radius","udp");

    $nasIP=explode(".",$_SERVER['SERVER_ADDR']);
    $ip=gethostbyname($radiushost);

    // 17 is UDP, formerly known as PROTO_UDP
    $sock=socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock==FALSE)
    {
        rcmail::write_log('otp', 'OTP Error: socket_create failed '.socket_strerror(socket_last_error()));
        echo "socket_create() failed: " . socket_strerror(socket_last_error()) . "\n";
        exit(0);
    }
    if ($debug)
    {
        rcmail::write_log('otp', 'OTP Debug: Sock '.$sock.' ip '.$ip.' port '.$radiusport);
    }
    $retval=socket_connect($sock,$ip,$radiusport);
    if ($retval==FALSE)
    {
        rcmail::write_log('otp', 'OTP Error: socket_connect failed '.socket_strerror(socket_last_error()));
        echo "socket_connect() failed: " . socket_strerror(socket_last_error()) . "\n";
        exit(0);
    }

    if (!preg_match("/@/",$username))
        $username.=$suffix;

    if ($debug)
        echo "<br>radius-port: $radiusport<br>radius-host: $radiushost<br>username: $username<br>suffix: $suffix<hr>\n";
    rcmail::write_log('otp', 'OTP Debug: username '.$username.' suffix '.$suffix);

    $RA=pack("CCCCCCCCCCCCCCCC",        // auth code
             1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255,
             1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255,
             1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255,
             1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255, 1+mt_rand()%255);
    if ($debug)
    {
        rcmail::write_log('otp', 'OTP Debug: sharedsecret: '.$sharedsecret.' RA : '.$RA);
        rcmail::write_log('otp', 'OTP Debug: type sharedsecret: '.gettype($sharedsecret).' type RA : '.gettype($RA));
    }
    $encryptedpassword=Encrypt($password,$sharedsecret,$RA);

    $length=4+                          // header
        16+                             // auth code
        6+                              // service type
        2+strlen($username)+            // username
        2+strlen($encryptedpassword)+   // userpassword
        6+                              // nasIP
        6;                              // nasPort

    $thisidentifier=mt_rand()%256;
    //          v   v v     v   v   v     v     v
    $data=pack("CCCCa*CCCCCCCCa*CCa*CCCCCCCCN",
        1,$thisidentifier,$length/256,$length%256,          // header
        $RA,                                                // authcode
        6,6,0,0,0,1,                                        // service type
        1,2+strlen($username),$username,                    // username
        2,2+strlen($encryptedpassword),$encryptedpassword,  // userpassword
        4,6,$nasIP[0],$nasIP[1],$nasIP[2],$nasIP[3],        // nasIP
        5,6,$_SERVER['SERVER_PORT']                         // nasPort
        );

    socket_write($sock,$data,$length);
    rcmail::write_log('otp', 'OTP Authentification: username '.$username.' password '.$password.' server '.$nasIP[0].'.'.$nasIP[1].'.'.$nasIP[2].'.'.$nasIP[3]);

    if ($debug)
    {
        rcmail::write_log('otp', 'OTP Debug: Writing '.$length.' bytes');
        echo "<br>writing $length bytes<hr>\n";
    }
    //
    // Wait at most five seconds for the answer. Thanks to
    // Michael Long <mlong@infoave.net> for his remark about this.
    //
    $read = array($sock);
    $num_sockets = socket_select($read, $write = NULL, $except = NULL, 60);
    if ($num_sockets === FALSE)
    {
        echo "socket_select() failed: " .
            socket_strerror(socket_last_error()) . "\n";
        socket_close($sock);
        rcmail::write_log('otp', 'OTP Error: socket_select() failed ');
        exit(0);
    } elseif ($num_sockets == 0)
    {
        echo "No answer from radius server, aborting\n";
        rcmail::write_log('otp', 'OTP Error: No answer from radius server, aborting ');
        socket_close($sock);
        exit(0);
    }
    unset($read);

    $readdata=socket_read($sock,2);
    socket_close($sock);
    if ($readdata===FALSE)
    {
        echo "socket_read() failed: " .
            socket_strerror(socket_last_error()) . "\n";
        rcmail::write_log('otp', 'OTP Error: socket_read() failed ');
        exit(0);
    }
    if (ord(substr($readdata, 1, 1)) != $thisidentifier)
    {
        //echo "Wrong id received from radius server, aborting\n";
        //exit(0);
        rcmail::write_log('otp', 'OTP Error: FIXME this is awfull');
        return 3; // FIXME this is awfull
    }
    rcmail::write_log('otp', 'OTP Authentification: Authentification for '.$username.' return code : '.ord($readdata));

    return ord($readdata);
    // 2 -> Access-Accept
    // 3 -> Access-Reject
    // See RFC2138 for this.
}

function Encrypt($password,$key,$RA)
{
  if ($debug)
  {
      rcmail::write_log('otp', 'OTP Debug: Encrypt Function');
  }

    $keyRA=$key.$RA;

    if ($debug)
        echo "<br>key: $key<br>password: $password<hr>\n";

    $md5checksum=md5($keyRA);
    $output="";

    for ($i=0;$i<=15;$i++)
    {
        if (2*$i>strlen($md5checksum))
            $m=0;
        else
            $m=hexdec(substr($md5checksum,2*$i,2));
        if ($i>strlen($keyRA))
            $k=0;
        else
            $k=ord(substr($keyRA,$i,1));
        if ($i>strlen($password))
            $p=0;
        else
            $p=ord(substr($password,$i,1));
        $c=$m^$p;
        $output.=chr($c);
    }
    return $output;
}
?>
