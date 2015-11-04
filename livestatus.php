<?php
/*****************************************************************************
 *
 * Modified and adjusted to work with OpenNetAdmin needs by Matt Pascoe.
 * TODO: at some point make this work with SSH keys to execute the unixcat
 * over an SSH connection.
 *
 * live.php - Standalone PHP script to serve the unix socket of the
 *            MKLivestatus NEB module as webservice.
 *
 * Copyright (c) 2010,2011 Lars Michelsen <lm@larsmichelsen.com>
 *
 * License:
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 * @AUTHOR   Lars Michelsen <lm@larsmichelsen.com>
 * @HOME     http://nagios.larsmichelsen.com/livestatusslave/
 * @VERSION  1.1
 *****************************************************************************/


$livestatusconf = Array(
    // The socket type can be 'unix' for connecting with the unix socket or 'tcp'
    // to connect to a tcp socket.
    'socketType'       => 'tcp',
    // When using a unix socket the path to the socket needs to be set
    'socketPath'       => '/var/run/nagios/rw/live',
    // When using a tcp socket the address and port needs to be set
    'socketAddress'    => '',
    'socketPort'       => '6557',
);


function connectLiveSocket($livestatusconf) {
     global $LIVE;
     // Create socket connection
     if($livestatusconf['socketType'] === 'unix') {
         if(!file_exists($conf['socketPath'])) {
           $LIVE = socket_create(AF_UNIX, SOCK_STREAM, 0);
         } else {
           return(array(1, "Unix socket ${livestatusconf['socketType']} doesnt exist."));
         }
     } elseif($livestatusconf['socketType'] === 'tcp') {
         $LIVE = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
     }

     if($LIVE == false) {
         return(array(1, 'Could not create livestatus socket connection.'));
     }

     // Set a timeout value for the socket currently 5 seconds
     socket_set_option($LIVE, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 3, 'usec' => 0));
     socket_set_option($LIVE, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 3, 'usec' => 0));
     
     // Connect to the socket
     if($livestatusconf['socketType'] === 'unix') {
         $result = socket_connect($LIVE, $livestatusconf['socketPath']);
     } elseif($livestatusconf['socketType'] === 'tcp') {
         $result = socket_connect($LIVE, $livestatusconf['socketAddress'], $livestatusconf['socketPort']);
     }
     
     if($result == false) {
         return(array(1, "Unable to connect to livestatus socket on {$livestatusconf['socketAddress']}."));
     }
 
     // Maybe set some socket options
     if($livestatusconf['socketType'] === 'tcp') {
         // Disable Nagle's Alogrithm - Nagle's Algorithm is bad for brief protocols
         if(defined('TCP_NODELAY')) {
             socket_set_option($LIVE, SOL_TCP, TCP_NODELAY, 1);
         } else {
             // See http://bugs.php.net/bug.php?id=46360
             socket_set_option($LIVE, SOL_TCP, 1, 1);
         }
     }
     return(array(0, 'Connection successful'));
 }


function commandLivestatus($cmd) {
    global $LIVE;
    // Query to get a json formated array back
    // Use fixed16 header
    socket_write($LIVE, $cmd . "\n");

    return(array(0, 'Command successful'));
}

function queryLivestatus($query) {
    global $LIVE;
    // Query to get a json formated array back
    // Use fixed16 header
    socket_write($LIVE, $query . "\nOutputFormat:json\nResponseHeader: fixed16\n\n");
    
    // Read 16 bytes to get the status code and body size
    $readfirst = $read = readLiveSocket(16);

    if($read === false)
        return(array(1, 'Problem while reading from socket: '.socket_strerror(socket_last_error($LIVE))));

    // Extract status code
    $status = substr($read, 0, 3);
    
    // Extract content length
    $len = intval(trim(substr($read, 4, 11)));
    
    // Read socket until end of data
    $read = readLiveSocket($len);

    if($read === false)
        return(array(1, 'Problem while reading from socket: '.socket_strerror(socket_last_error($LIVE))));
    
    // Catch errors (Like HTTP 200 is OK)
    if($status != "200") {
        $statusfull = readLiveSocket(100);
        return(array(1, "socket: {$readfirst}{$statusfull}"));
    }
    
    // Catch problems occured while reading? 104: Connection reset by peer
    if(socket_last_error($LIVE) == 104)
        return(array(1, 'Problem while reading from socket: '.socket_strerror(socket_last_error($LIVE))));
    
    // Decode the json response
    $obj = json_decode(utf8_encode($read));
    
    // json_decode returns null on syntax problems
    if($obj === null)
        return(array(1, 'The response has an invalid format'));
    else
        return(array(0,$obj));
}


function closeLiveSocket() {
    global $LIVE;
    @socket_close($LIVE);
    $LIVE = null;
}

function readLiveSocket($len) {
    global $LIVE;
    $offset = 0;
    $socketData = '';
    
    while($offset < $len) {
        if(($data = @socket_read($LIVE, $len - $offset)) === false)
            return false;
    
        $dataLen = strlen ($data);
        $offset += $dataLen;
        $socketData .= $data;
        
        if($dataLen == 0)
            break;
    }
    
    return $socketData;
}


// Test 
//connectLiveSocket();
//print_r(queryLivestatus("GET hosts\nColumns: host_name services_with_info"));
//closeLiveSocket();




/*
// testing I had done using ssh.. its not even close to ready but it worked

class NiceSSH {
    // SSH Host
    private $ssh_host = 'nagiosserver.example.com';
    // SSH Port
    private $ssh_port = 22;
    // SSH Server Fingerprint
    private $ssh_server_fp = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    // SSH Username
    private $ssh_auth_user = 'user';
    // SSH Public Key File
    private $ssh_auth_pub = './ona_nagios.pub';
    // SSH Private Key File
    private $ssh_auth_priv = './ona_nagios';
    // SSH Private Key Passphrase (null == no passphrase)
    private $ssh_auth_pass;
    // SSH Connection
    private $connection;

    public function connect() {
        if (!($this->connection = ssh2_connect($this->ssh_host, $this->ssh_port))) {
            throw new Exception('Cannot connect to server');
        }
//        $fingerprint = ssh2_fingerprint($this->connection, SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX);
//        if (strcmp($this->ssh_server_fp, $fingerprint) !== 0) {
//            throw new Exception('Unable to verify server identity!');
//        }
        if (!ssh2_auth_pubkey_file($this->connection, $this->ssh_auth_user, $this->ssh_auth_pub, $this->ssh_auth_priv, $this->ssh_auth_pass)) {
            throw new Exception('Autentication rejected by server');
        }
    }
    public function exec($cmd) {
        if (!($stream = ssh2_exec($this->connection, $cmd))) {
            throw new Exception('SSH command failed');
        }
        stream_set_blocking($stream, true);
        $data = "";
        while ($buf = fread($stream, 4096)) {
            $data .= $buf;
        }
        fclose($stream);
        return $data;
    }
    public function disconnect() {
       // $this->exec($this->connection, 'exit;');
        $this->connection = null;
    }
    public function __destruct() {
        $this->disconnect();
    }
}


$cn = new NiceSSH();
$cn->connect();
echo $cn->exec("echo 'GET contacts' | unixcat /var/lib/nagios3/rw/live");
//echo $cn->exec('ls /tmp');
$cn->disconnect();

*/

?>
