<?php
/* rtspdump.php -- MS-RTSP streaming program
  version 2.6, January 9th, 2011 and July 25, 2012

  Copyright (C) 2010 Joel Yliluoma

  This software is provided 'as-is', without any express or implied
  warranty.  In no event will the authors be held liable for any damages
  arising from the use of this software.

  Permission is granted to anyone to use this software for any purpose,
  including commercial applications, and to alter it and redistribute it
  freely, subject to the following restrictions:

  1. The origin of this software must not be misrepresented; you must not
     claim that you wrote the original software. If you use this software
     in a product, an acknowledgment in the product documentation would be
     appreciated but is not required.
  2. Altered source versions must be plainly marked as such, and must not be
     misrepresented as being the original software.
  3. This notice may not be removed or altered from any source distribution.
  4. Relicensing under GPL is explicitly allowed.

  Joel Yliluoma bisqwit@iki.fi
*/
if(!defined('RTSPDUMP_MAIN_INCLUDED')) die('The main program is rtspdump.php, not '.__FILE__."\n");

// There is no fgets() equivalent in PHP for sockets created with socket_create().
// There is one for fsockopen(), but we cannot use fsockopen(), because we must
// also include the RTSP socket in the socket_select() call, which only accepts
// sockets created with socket_create().
function socket_readline($sock)
{
  $line = '';
  for(;;)
  {
    $c = socket_read($sock, 1);
    if($c === false || $c === '')
      { print "RTSP socket error: ". socket_strerror(socket_last_error()); return "\r\n"; }
    $line .= $c;
    if($c == "\n") break;
  }
  return $line;
}

class RTSP
{
  var $sock;
  var $stream;
  var $session;
  var $playlist_id;

  function __construct($stream)
  {
    $this->stream = $stream;
    $this->session     = '';
    $this->playlist_id = 1;
  }

  function Connect()
  {
    global $verbose;

    $url_components = parse_url($this->stream);
    $dst_host = $url_components['host'];
    $dst_addr = gethostbyname($dst_host);
    $rtsp_port = @$url_components['port'];
    if(!$rtsp_port) $rtsp_port = 554;
    // Change protocol to rtsp in case it was given as mms:// or http:// .
    $this->stream = "rtsp://$dst_host{$url_components['path']}";
    // WMS won't care about the host name, but it's nice to keep it.

    if($verbose) print "Connecting to {$dst_addr}, $rtsp_port for {$this->stream}\n";
    $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if(!socket_connect($this->sock, $dst_addr, $rtsp_port))
    {
      print "RTSP connect error: ". socket_strerror(socket_last_error()) . "\n";
      exit;
    }
  }

  private function ReadResponse()
  {
    $length = 0;
    $result = '';
    for(;;)
    {
      $line = socket_readline($this->sock);
      if($line == "\r\n") break;
      $result .= $line;
      if(preg_match('/^Content-length:/i', $line))
        $length = (int)preg_replace('@^.*:@', '', $line);
    }
    while($length > 0)
    {
      $s = socket_read($this->sock, $length);
      if($s === false || $s === '')
        { print "RTSP socket error: ". socket_strerror(socket_last_error()). "\n"; return ""; }
      $result .= $s;
      $length -= strlen($s);
    }
    return $result;
  }

  function SendRequest($command, $request)
  {
    global $debug, $verbose;

    if($verbose) print "Sending {$command}\n";
    if($debug & DEBUG_RTSP) print $request;

    for($remain = $request; strlen($remain) > 0; )
    {
      $s = socket_write($this->sock, $remain);
      if($s === false) { print "RTSP socket error: ". socket_strerror(socket_last_error()). "\n"; return; }
      $remain = substr($remain, $s);
    }
  }

  function DoRequest($command, $request)
  {
    global $verbose, $debug;

    $this->SendRequest($command, $request);
    $s = $this->ReadResponse();
    $c = func_num_args();
    foreach(explode("\r\n", $s) as $line)
    {
      $captures = Array();
      for($a=2; $a<$c; ++$a)
      {
        $arg = func_get_arg($a);
        if(is_array($arg[0])) $captures = $arg; else $captures[] = $arg;
      }
      foreach($captures as $arg)
      {
        $tmp = preg_match($arg[1], $line, $mat);
        if($tmp)
        {
          $tmp = $mat[1];
          $var = $arg[0];
          global $$var;
          eval('$$var = '.str_replace('$1', '$tmp', $arg[2]).';');
          if($debug & DEBUG_MISC)
          {
            if($var == 'asf_header')
              printf("\$%-16s set to binary string (%d bytes)\n", $var, strlen($$var));
            else
              printf("\$%-16s set to %s\n", $var, $$var);
          }
        }
      }
    }

    if($debug & DEBUG_RTSP)
      print "[$command]\n$s\n\n";

    if(substr($s, 0, 24) == 'RTSP/1.0 502 Bad Gateway'
    || substr($s, 0, 24) == 'RTSP/1.0 500 Internal Se')
    {
      $type = 'error';
      if($command == 'LogConnect'
      || $command == 'UdpPacketPair'
      || $command == 'TcpPacketPair'
      || $command == 'KeepAlive') $type = 'warning';
      print "RTSP protocol $type: Server does not seem to agree with our intents on $command\n";
      if($type == 'error')
      {
        if(!($debug & DEBUG_RTSP))
        {
          print
            preg_replace('/^/m', '  ',
              "---Failing request:---\n$request\n---Response indicating failure:---\n$s\n");
        }
      }
      sleep(2);
      if($command == 'Describe')
      {
        socket_close($this->sock);
        sleep(5);
        $this->Connect();
      }
      return $this->DoRequest($command, $request);
    }
    if(substr($s, 0, 13) != 'RTSP/1.0 200 ')
    {
      $type = 'error';
      if($command == 'LogConnect'
      || $command == 'UdpPacketPair'
      || $command == 'TcpPacketPair'
      || $command == 'KeepAlive') $type = 'warning';
      print "RTSP protocol $type: Server does not seem to agree with our intents on $command\n";
      if($type == 'error')
      {
        if(!($debug & DEBUG_RTSP))
        {
          print
            preg_replace('/^/m', '  ',
              "---Failing request:---\n$request\n---Response indicating failure:---\n$s\n");
        }
        exit;
      }
    }

    return $s;
  }

  function FindStreams()
  {
    global $debug;

    /* A DESCRIBE request is not required to get streaming
     * started, if you already know all the required information.
     * But for ASF, we really need the asfv1 and maxps fields.
     * Otherwise we cannot produce working ASF files. In general,
     * it is a good idea to get the stream names from the server.
     */
    $s = $this->DoRequest('Describe',
      "DESCRIBE {$this->stream} RTSP/1.0\r\n".
      "User-Agent: ".USER_AGENT."\r\n".
       "\r\n",
      Array('asf_header',  '/.*asfv1;base64,(.*)/', 'base64_decode($1)'), // ASF file header
      Array('maxps',       '/.*maxps:(.*)/',        '(int)$1'),           // Maximum packet size
      Array('playlist_id', '/X-Playlist-Gen-Id: *(.*)/', '$1')            // Playlist ID
    );
    $streamno = -1;
    $streams = Array();
    foreach(explode("\r\n", $s) as $line)
    {
      if(preg_match('/^m=/', $line)) ++$streamno; // stream number
      if($streamno < 0) continue;
      $tmp = preg_match('/^a=control:(.*)/', $line, $mat);
      if($tmp) $streams[$streamno]['control'] = $mat[1];
      $tmp = preg_match('/^m=(.*?) /', $line, $mat);
      if($tmp) $streams[$streamno]['category'] = $mat[1];
      $param = '(?:/([^/]*))?';
      $tmp = preg_match("@^a=rtpmap:([0-9]+) ([^/]*){$param}{$param}{$param}@", $line, $mat);
      if($tmp) { $streams[$streamno]['payloadtype'] = (int)$mat[1];
                 $streams[$streamno]['mediatype'] = $mat[2];
                 $streams[$streamno]['param1'] = @$mat[3];
                 $streams[$streamno]['param2'] = @$mat[4];
                 $streams[$streamno]['param3'] = @$mat[5]; }
      $tmp = preg_match("@^a=fmtp:([0-9]+) (.*)@", $line, $mat);
      if($tmp)
      {
        preg_match_all('/([^=]*)=(.*?);/', $mat[2].';', $mat);
        foreach($mat[1] as $keyindex => $keyvalue)
          $streams[$streamno][$keyvalue] = $mat[2][$keyindex];
      }
    }

    global $playlist_id;
    $this->playlist_id = $playlist_id;

    // Rename the streams according to their control points
    $tmp = $streams; $streams = Array();
    foreach($tmp as $streamno => $streamcfg)
      $streams[$streamcfg['control']] = $streamcfg;

    if($debug & DEBUG_MISC)
    {
      print "Discovered these streams:\n";
      print_r($streams);
    }
    return $streams;
  }

  function SubscribeToStreams(&$streams)
  {
    global $av_port, $rtx_port;
    global $server_ssrc, $server_port;
    global $session;

    /* Issue the SelectStream commands */
    foreach($streams as $control => &$streamcfg)
    {
      $server_ssrc = '00000000';
      $server_port = 0;
      $captures = Array(
        Array('session',          '/^Session: *([^;]*)/', '$1'),
        Array('rtsp_timeout',     '/timeout=([0-9]+)/', '(int)$1'),
        Array('server_ssrc',      '/ssrc=([0-9a-fA-F]+)/', 'base_convert($1, 16,10)'),
        Array('server_port',      '/server_port=(?:[0-9]*-)?([0-9]+)/', '(int)$1')
      );
      // Suggest a ssrc to the server. WMS ignores this value.
      // DSS echoes it back. So far, there does not seem to be
      // purpose in specifying a value.
      #$ssrc = sprintf(';ssrc=%08x', rand(0,0x7FFFFFFF);
      $ssrc = '';
      $transport_hdr = ($av_port === 'tcp'
          ? "Transport: RTP/AVP/TCP;unicast;interleaved=0-1\r\n"
          : "Transport: RTP/AVP/UDP;unicast;client_port=$av_port{$ssrc};mode=PLAY\r\n");
      if($streamcfg['mediatype'] == 'x-wms-rtx')
      {
        if($av_port === 'tcp') continue; // Skip Microsoft's Retransmission stream when in TCP mode
        // Retransmission stream is always UDP
        $transport_hdr = "Transport: RTP/AVP/UDP;unicast;client_port=$rtx_port{$ssrc};mode=PLAY\r\n";
      }
      $slash = '/';
      if(preg_match('@/$@', $this->stream)) $slash = '';
      $this->DoRequest("SelectStream {$control}",
        "SETUP {$this->stream}$slash{$control} RTSP/1.0\r\n".
        "User-Agent: ".USER_AGENT."\r\n".
        "Session: {$this->session}\r\n".
        //"x-Retransmit: our-retransmit\r\n". // this is for DSS
        "X-Playlist-Gen-Id: {$this->playlist_id}\r\n".
        $transport_hdr.
        "\r\n",
        $captures
      );
      $streamcfg['ssrc'] = $server_ssrc;
      $streamcfg['port'] = $server_port;

      $this->session = $session;
    }
  }

  function PrepareStreaming()
  {
    /////////////////
    /* This is completely optional. It is supposed to be used
     * for estimating the bandwidth, but it is not specified
     * how it should be used, and it has no practical purpose
     * other than that. (MS-RTSP only)
     */
    /*$param = 'type: high-entropy-packetpair variable-size';
    DoRTSPrequest($rtsp_sock, 'UdpPacketPair',
      "SET_PARAMETER {$this->stream} RTSP/1.0\r\n".
      "User-Agent: ".USER_AGENT."\r\n".
      "Session: {$this->session}\r\n".
      "Content-type: application/x-rtsp-udp-packetpair;charset=UTF-8\r\n".
      "Content-length: ".strlen($param)."\r\n".
      "\r\n".
      $param
    );*/

    /////////////////
    /* This is completely optional, but the concept of
     * making "Linux" appear in the media server's logs
     * for the first time is intriguing. (MS-RTSP only)
     */
    $param = '<XML><c-os>'.MY_OS.'</c-os><c-osversion>'.MY_OSVERSION.'</c-osversion></XML>';
    $this->DoRequest('LogConnect',
      "SET_PARAMETER {$this->stream} RTSP/1.0\r\n".
      "User-Agent: ".USER_AGENT."\r\n".
      "Session: {$this->session}\r\n".
      "Content-type: application/x-wms-Logconnectstats;charset=UTF-8\r\n".
      "Content-length: ".strlen($param)."\r\n".
      "\r\n".
      $param
    );
  }

  function BeginStreaming()
  {
    global $begin_offset_seconds, $run_for;
    $npt   = sprintf('%.3f-', $begin_offset_seconds);
    $smpte = sprintf('%d:%d:%d:%.2f-',
        (int)(((int)$begin_offset_seconds) / 3600),
        (int)(((int)$begin_offset_seconds) / 60) % 60,
        (int)(((int)$begin_offset_seconds) % 60),
        29.97 * ($begin_offset_seconds - (int)$begin_offset_seconds));

    if($run_for < 999999999)
    {
      $t = $begin_offset_seconds + $run_for + 30.0;
      $npt .= sprintf('%.3f', $t);
      $smpte .= sprintf('%d:%d:%d:%.2f',
          (int)(((int)$t) / 3600),
          (int)(((int)$t) / 60) % 60,
          (int)(((int)$t) % 60),
          29.97 * ($t - (int)$t));
    }
    /*
    To seek to a starting position, use, in PLAY,
      Range: npt=12345-            Time position (seconds) from start of presentation
      Range: smpte=1:2:3:4.5-      Time position from start of clip  
      Range: x-asf-packet=12345-   Microsoft extension
      Range: x-asf-byte=1234567-   Microsoft extension
    */
    $this->DoRequest('Play',
      "PLAY {$this->stream} RTSP/1.0\r\n".
      "User-Agent: ".USER_AGENT."\r\n".
      "X-Playlist-Gen-Id: {$this->playlist_id}\r\n".
      "Session: {$this->session}\r\n".
      "Range: smpte={$smpte}\r\n".
      "Bandwidth: 2147483647\r\n".
      "X-Accelerate-Streaming: AccelDuration=8000;AccelBandwidth=5912000\r\n".
      "\r\n",
      Array('audio_seq', '/audio;seq=([0-9]+)/', '(int)$1'),
      Array('video_seq', '/video;seq=([0-9]+)/', '(int)$1'),
      Array('first_seq', '/seq=([0-9]+)/', '(int)$1')
    );
  }

  function EndStreaming()
  {
    // Use SendRequest rather than DoRequest, because
    // we may be in TCP streaming mode and get RTP data
    // as response. And we don't care about the response, either.
    $this->SendRequest('Pause',
      "PAUSE {$this->stream} RTSP/1.0\r\n".
      "User-Agent: ".USER_AGENT."\r\n".
      "Session: {$this->session}\r\n".
      "\r\n");
    $this->SendRequest('Teardown',
      "TEARDOWN {$this->stream} RTSP/1.0\r\n".
      "User-Agent: ".USER_AGENT."\r\n".
      "Session: {$this->session}\r\n".
      "\r\n");
    socket_close($this->sock);
  }

  function SendKeepAlive()
  {
    $this->SendRequest('KeepAlive',
      "GET_PARAMETER {$this->stream} RTSP/1.0\r\n".
      "Session: {$this->session}\r\n".
      "\r\n"
     );
    // The response will be dealt by TCP_buffer_to_RTP().
  }
};

/* The above is simply RTSP specs.
 * ASF specification:
 *   http://www.microsoft.com/windows/windowsmedia/forpros/format/asfspec.aspx
 * RTP header:
 *   RFC, http://www.ietf.org/rfc/rfc3550.txt
 * RTP payload specification:
 *   http://msdn.microsoft.com/en-us/library/cc245257%28PROT.10%29.aspx
 * RTCP NACK (retransmit request):
 *   http://www.ietf.org/rfc/rfc4585.txt (Generic NACK)
 * Microsoft's special requirements for RTCP NACK:
 *   http://msdn.microsoft.com/en-us/library/cc245269%28PROT.10%29.aspx
 */
