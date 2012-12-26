<?php
/* rtspdump.php -- MS-RTSP streaming program
  version 2.6, January 9th, 2011 and July 25, 2012

  Copyright (C) 2010l Joel Yliluoma

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

define('STATE_RECEIVED',      0);
define('STATE_REQUESTED',     1);
define('STATE_WISHING',       2);
define('STATE_GAVEUP',        3);

/*
See: http://en.wikipedia.org/wiki/RTP_audio_video_profile
*/

function TCP_buffer_to_RTP($data)
{
  /* In the TCP stream, each RTP packet is encapsulated
   * with a 4-byte packet, where two unknown-meaning bytes
   * prefix a two-byte length value of the RTP packet.
   * Because TCP is streaming, RTP packets may be split
   * between different recv()s, and we must therefore
   * use a buffering scheme.
   * In UDP, the length is that of the packet itself.
   */
  static $remain = 0, $buffer = '', $rest = '', $handling_rtsp_response = false;
  $pos    = 0;
  if($rest != '') { $rest .= $data; $data = $rest; $rest = ''; }
  $length = strlen($data);
  while($pos < $length)
  {
    if(!$remain)
    {
      if($pos+4 > $length) return null;

      if($handling_rtsp_response)
      {
        $pos = strpos($data, "\r\n\r\n", $pos);
        if($pos === false)
        {
          $rest = substr($data, -3);
          return null;
        }
        $pos += 4;
        $handling_rtsp_response = false;
        continue;
      }
      if(substr($data, $pos, 9) == 'RTSP/1.0 ')
      {
        $handling_rtsp_response = true;
        $pos += 9;
        continue;
      }
      $tmp = unpack('Ca/Cb/nlength', substr($data, $pos, 4));
      $pos += 4;
      $remain = $tmp['length'];
      continue;
    }
    $take = $remain;
    if($pos+$take > $length) $take = $length-$pos;
    $buffer .= substr($data, $pos, $take);
    $remain -= $take;
    $pos += $take;
    if($remain == 0)
      { $rest .= substr($data, $pos); $result = $buffer; $buffer = ''; return $result; }
  }
  return null;
}

/* ReceiveRTP: Receive a RTP packet from any of the sockets we might
 * receive them through. Parse the RTP header, but don't act on it.
 */
function ReceiveRTP()
{
  global $av_sock, $maxps, $rtx_sock, $rtx_port, $av_port, $rtsp;
  global $debug, $n_net_bytes;

  $packet_source = '';
  $rtp  = TCP_buffer_to_RTP(''); // Check if the TCP buffer contains RTP packets
  $host = '127.0.0.1';
  $port = 0;
  while($rtp === null)
  {
    $read = Array($rtsp->sock);
    if($av_port !== 'tcp')
    {
      $read[] = $av_sock;
      $read[] = $rtx_sock;
    }
    $w=null;
    $e=null;
    if (0 == socket_select($read,$w,$e, 0, 10000)) {
      return false;
    }
    foreach($read as $sock)
    {
      $n = socket_recvfrom($sock, $rtp, $maxps+64, 0, $host, $port);
      if(!$n)
      {
        print "Receive from socket failed: ".socket_strerror(socket_last_error())."\n";
        exit;
      }

      $n_net_bytes += strlen($rtp) + 8;

      /* MS-RTSP:
       *  UDP mode:
       *   Audio and video are both received through $av_sock, $av_port
       *   Retransmitted packets are received through $rtx_sock, $rt_port
       *   Retransmissions are requested through $rtx_sock, server's rtx port
       *  TCP mode:
       *   Audio and video are both received through $rtsp->sock
       */
      if($sock === $rtx_sock)    $packet_source = "$host:{$port}->$rtx_port";
      elseif($sock === $av_sock) $packet_source = "$host:{$port}->$av_port";
      else
      {
        $n_net_bytes += 20-8; // assume minimal length TCP packet
        $packet_source = 'TCP';
        // Push the raw data into a buffer and parse it for any RTP packets
        $rtp = TCP_buffer_to_RTP($rtp);
      }
      break;
    }
  }

  // Parse RTP packet header
  $hdr = unpack('Cconfig/Cpayloadtype/nseqno/Ntimestamp/Nssrc', $rtp);
  extract($hdr);

  $hdr['mark']        = $hdr['payloadtype'] >= 0x80;
  $hdr['payloadtype'] &= 0x7F;
  $hdr['host'] = $host;
  $hdr['port'] = $port;

  $payload_offs = 12 + ($config & 0x0F) * 4;
  $payload = substr($rtp, $payload_offs);

  /*if($timestamp == 0) // High-entropy packetpair?
  {
    if($debug & DEBUG_RTP_INCOMING)
    {
      $hdr = unpack('Nlength', $payload);
      printf("Received RTP %X (%d bytes) from $packet_source -- packetpair data\n", $seqno, strlen($rtp));
      printf("  %02X %02X %04X %08X %08X, %02X %06X ...\n",
        $config, $payloadtype, $seqno, $timestamp, $ssrc,
        ord($payload[0]), $hdr['length'] & 0xFFFFFF);
    }
    return ReceiveRTP();
  }*/

  if($debug & DEBUG_RTP_INCOMING)
    printf("Received RTP #%X (%d/%d bytes) from $packet_source -- TS=%d,SSRC=%X,PT=%u%s\n",
      $seqno, strlen($payload), strlen($rtp), $timestamp, $ssrc, $hdr['payloadtype'],
      $hdr['mark'] ? ',MARK' : '');

  return Array($hdr, $payload);
}

/* RTPpacketSequencer is responsible for putting RTP packets in the right
 * order and for asking the server to retransmit missing packets.
 * If we did not care for transmission errors, this code would be
 * a _lot_ simpler. Simple recvfrom+unpack+return would suffice.
 */
class RTPpacketSequencer
{
  /* Of the last received packet, record the SSRC, host and IP port */
  var $last_ssrc;
  var $last_host;
  var $last_port;

  /* List of
  var $available_packets;

  /* Number of times the seqno has wrapped around, times 0x10000 */
  /* Add this to seqno to get extended seqno, which has no wraparound. */
  var $seqno_add;


  var $highest_seqno;     /* Highest seen extended seqno */
  var $prev_returned;     /* Last in-sequence seqno we've returned */
  var $missing_situation; /* Are we currently waiting for a missed packet? */

  var $first_acknowledged; /* Quicktime acknowledge seqno */
  var $acknowledge_mask;   /* Quicktime acknowledge seqno mask */

  function __construct()
  {
    global $first_seq;

    $this->available_packets = Array();
    $this->last_ssrc = 0;
    $this->seqno_add = 0;
    $this->highest_seqno = 0;
    /* TODO: Choose between audio_seq and video_seq in a more
     *       intelligent manner. This choice here is just based
     *       on what the server did in my test case.
     */
    $this->prev_returned = $first_seq-1;
    $this->missing_situation = false;

    $this->first_acknowledged = 0;
    $this->acknowledge_mask   = 0;
  }

  /* Receive RTP data from the media server */
  function HandleIncomingPacket($packet)
  {
    global $debug;

    $hdr     =&$packet[0];        /* RTP headers */
    /*$payload = $packet[1];*/    /* Payload */
    $packet[2] = STATE_RECEIVED;  /* State */
    $packet[3] = 0;               /* Number of frames it has been missing */
    $packet[4] = 0;               /* Frame where retransmit was last requested */

    $seqno = &$hdr['seqno'];

    $this->last_ssrc = $hdr['ssrc'];
    $this->last_host = $hdr['host'];
    $this->last_port = $hdr['port'];

    $seqno += $this->seqno_add;
    // Check if the seqno wrapped around
    if($seqno > $this->highest_seqno+50000 && $seqno >= 65536 && $this->prev_returned != 0)
      $seqno -= 65536;
    while($seqno < $this->highest_seqno-50000)
    {
      $this->seqno_add += 65536;
      $seqno += 65536;
    }
    if($seqno > $this->highest_seqno)
      $this->highest_seqno = $seqno;

    if($this->prev_returned == 0)
      $this->prev_returned = $seqno-1;

    /*
    if($seqno < $this->first_acknowledged
    || $seqno >= $this->first_acknowledged+6)
    {
      if($this->first_acknowledged)
        $this->Send_QT_Acknowledge_Request
          ($this->first_acknowledged & 0xFFFF, $this->acknowledge_mask);
      $this->first_acknowledged = $seqno;
      $this->acknowledge_mask   = 0;
    }
    else
      $this->acknowledge_mask |= 1 << ($seqno - $this->first_acknowledged);
    */

    if(isset($this->available_packets[$seqno])
    && $this->available_packets[$seqno][2] == STATE_REQUESTED)
    {
      global $n_retransmit_received;
      ++$n_retransmit_received;
    }

    $this->available_packets[$seqno] = $packet;
  }

  /* Return the sequentially next RTP packet, if available. If not, return null. */
  function RetrieveNextPacket()
  {
    global $debug;
    global $buffer_length_in_packets, $av_port;

    $packets = &$this->available_packets;

    if(empty($packets))
    {
      return null;
    }

    // find the lowest and highest seqno
    $keys = array_keys($packets);
    $lowest_seqno  = min($keys);

    if(count($packets) < $buffer_length_in_packets)
    {
      // always try to keep a few in the buffer
      return null;
    }

    $want_next = $this->prev_returned + 1;

    /*if($debug & DEBUG_RTP_REORDERING)
        printf("%08X Hoping for #%X, first=#%X, last is #%X...\n",
          $this->last_ssrc,
          $want_next, $lowest_seqno, $this->highest_seqno);*/

    if($lowest_seqno == $this->prev_returned + 1)
    {
      $lowest = $packets[$lowest_seqno];
      if($lowest[2] == STATE_RECEIVED)
      {
        /* Return the packet if it's what we wanted next. */
        if($this->missing_situation)
        {
          if($debug & DEBUG_RTP_REORDERING)
            printf("%08X Got #%X, back on track\n", $this->last_ssrc, $lowest_seqno);
          $this->missing_situation = false;
        }
        $this->prev_returned = $lowest_seqno;
        unset($packets[$lowest_seqno]);

        return $lowest;
      }
      if($lowest[2] == STATE_GAVEUP)
      {
        /* If we have not received this packet despite
         * numerous requests to retransmit, give up
         * and move on.
         */
        global $n_dropped;
        ++$n_dropped;
        if($debug & DEBUG_RTP_REORDERING)
          printf("%08X Gives up on #%X\n", $this->last_ssrc, $lowest_seqno);
        $this->prev_returned = $lowest_seqno;
        unset($packets[$lowest_seqno]);
        return $this->RetrieveNextPacket();
      }
    }
    elseif($lowest_seqno <= $this->prev_returned)
    {
      /* Discard a packet we have already processed.
       * This can happen for numerous reasons:
       * - Internet can duplicate packets
       * - Original packet is received after retransmission was requested
       */
      if($debug & DEBUG_RTP_REORDERING)
        printf("%08X Discards duplicate #%X\n", $this->last_ssrc, $lowest_seqno);
      //$this->prev_returned = $lowest_seqno;
      unset($packets[$lowest_seqno]);
      return $this->RetrieveNextPacket();
    }
    else
    {
      /* We have packets in buffer */
      if($debug & DEBUG_RTP_REORDERING)
          printf("%08X Missing #%X, last is #%X...\n",
            $this->last_ssrc, $this->prev_returned+1, $this->highest_seqno);
      $lowest_seqno = $this->prev_returned+1;
      $this->missing_situation = true;
    }

    $highest_seqno = $this->highest_seqno;

    /* Mark missing packets as wishes */
    for($seqno = $lowest_seqno; $seqno < $highest_seqno; ++$seqno)
      if(!isset($packets[$seqno]))
        $packets[$seqno] = Array(null,null, STATE_WISHING, 0,0);

    /* Create retransmit requests for:
     * - Packets that we still don't have
     * - Beginning from the ones we need _now_
     * - But not everything at once
     */
    for($seqno = $lowest_seqno; $seqno < $highest_seqno; ++$seqno)
    {
      if($packets[$seqno][2] == STATE_RECEIVED)
        continue;

      ++$packets[$seqno][3]; // increase waiting time
      if($packets[$seqno][3] >= RETRANSMIT_GIVEUP_INTERVAL)
      {
        // Doesn't look like we're going to ever receive it
        $packets[$seqno][2] = STATE_GAVEUP;
        continue;
      }

      if($av_port !== 'tcp') // If TCP is used, retransmissions cannot be requested
      {
        // Do we need to send a retransmit request?
        if($packets[$seqno][2] != STATE_REQUESTED
        || $packets[$seqno][3] >= $packets[$seqno][4]+RETRANSMIT_RETRY_INTERVAL
          )
        {
          // Check if the preceding seqno would very soon be also requested
          while($seqno > $lowest_seqno
             && $packets[$seqno-1][2] == STATE_REQUESTED
             && $packets[$seqno-1][3] >= $packets[$seqno-1][4]+RETRANSMIT_RETRY_INTERVAL-RETRANSMIT_RETRY_INTERVAL_GREATER_MARGINAL)
          {
            --$seqno;
          }
          // Send a new request
          $this->Generate_Retransmit_Request($seqno);
          // Enough for now, don't clog the pipes
          break;
        }
      }
    }
    // nothing to send right now
    return null;
  }

  private function Generate_Retransmit_Request($first_seqno)
  {
    global $debug;
    global $n_retransmit_requested;

    $packets = &$this->available_packets;
    $highest_seqno = $this->highest_seqno;

    // For debugging, list the seqnos we are requesting for
    $requests = sprintf('%X(%d)', $first_seqno, $packets[$first_seqno][3]);

    // The given packet will be requested
    if($packets[$first_seqno][2] != STATE_RECEIVED)
    {
      $packets[$first_seqno][2] = STATE_REQUESTED;
      $packets[$first_seqno][4] = $packets[$first_seqno][3]; // just requested
    }
    ++$n_retransmit_requested;

    // But with the same request, we can ask for 16 other packets too.
    // Find out if any of the next 16 packets are in need of retransmission.
    $bitmask = 0;
    $bitpos  = 1;
    for($seqno = $first_seqno + 1;
        $seqno < $highest_seqno && $bitpos < 0x10000;
        ++$seqno)
    {
      // If it has not been received, and it's not currently being requested
      if($packets[$seqno][2] != STATE_RECEIVED
      || ($packets[$seqno][2] == STATE_REQUESTED // in case Retransmit failed
        && $packets[$seqno][3] >= $packets[$seqno][4]+RETRANSMIT_RETRY_INTERVAL-RETRANSMIT_RETRY_INTERVAL_SMALLER_MARGINAL
        ))
      {
        $bitmask |= $bitpos;
        $packets[$seqno][2] = STATE_REQUESTED;
        $packets[$seqno][4] = $packets[$seqno][3]; // just requested

        // This list is for debugging
        $requests .= sprintf(' %X(%d)', $seqno, $packets[$seqno][3]);
        ++$n_retransmit_requested;
      }
      $bitpos <<= 1;
    }
    if($debug & DEBUG_RTP_REORDERING)
      printf("%08X Requesting retransmit for: %s\n", $this->last_ssrc, $requests);
    $this->Send_Retransmit_Request($first_seqno, $bitmask);
  }

  private function Send_Retransmit_Request(
    $first_seqno,
    $seqno_mask)
  {
    global $rtx_sock, $streams;
    $rtcp = $this->MakeRTCP_NACK($first_seqno, $seqno_mask);
    $host = $this->last_host;
    $port = $this->last_port;
    if(isset($streams['rtx']))
    {
      // If the server uses a dedicated RTX port, use that
      $port = $streams['rtx']['port'];
    }
    socket_sendto($rtx_sock, $rtcp, strlen($rtcp), 0, $host, $port);
  }

  private function Send_QT_Acknowledge_Request(
    $first_seqno, $seqno_mask)
  {
    global $rtx_sock, $streams, $debug;
    $rtcp = $this->MakeRTCP_ACK($first_seqno, $seqno_mask);
    $host = $this->last_host;
    $port = $this->last_port;
    if($debug & DEBUG_RTP_REORDERING)
      printf("%08X Sending acknowledge for #%04X, mask #%08X\n",
        $this->last_ssrc, $first_seqno, $seqno_mask);
    socket_sendto($rtx_sock, $rtcp, strlen($rtcp), 0, $host, $port+1);
  }

  private function MakeRTCP_NACK($first_seqno, $seqno_mask)
  {
    global $my_ssrc_for_rtcp_nacks, $streams;

    $my_ssrc = $my_ssrc_for_rtcp_nacks;

    $rtcp_ssrc = 0;
    if(isset($streams['rtx']))
    {
      $rtcp_ssrc = $streams['rtx']['ssrc'];
    }

    $rtcp = pack('CCnN',
      0x80, /* reception report count: 0 */
      201, /* receiver report */
      1, $my_ssrc);

    // Do not change this constant, Microsoft requires it to be exactly like this.
    $cname = $rtcp_ssrc . '@WMS:7ff42e07-3c7c-4eb5-9c17-6bdd11ad90de';
    $cname_length = strlen($cname);
    for($c=$cname_length; $c<54; ++$c) $cname .= chr(0);

    $rtcp .= pack('CCnNCCA*',
      0x81, /* source count:  1 */
      202, /* source description (SDES) */
      15, $my_ssrc, // 15 is the number of dwords in this packet, - 1
      1, /* cname */
      $cname_length, /* length of the string: */
      $cname); /* string and padding */

    $rtcp .= pack('CCnNNnn',
      0x81,/* feedback message type (FMT): 1 - request for retransmission */
        /* Use 0x91 to set E=1 (NACK) bit for Microsoft (not required?) */
      205, /* generic rtp feedback (RTPFB) */
      3, $my_ssrc, // 3 is the number of dwords in this packet, - 1
      $this->last_ssrc,
      $first_seqno & 0xFFFF,
      $seqno_mask);

    return $rtcp;
  }

  private function MakeRTCP_ACK($first_seqno, $seqno_mask)
  {
    global $my_ssrc_for_rtcp_nacks, $streams;

    $my_ssrc = $my_ssrc_for_rtcp_nacks;

    $rtcp = pack('CCnN',
      0x80, /* reception report count: 0 */
      201, /* receiver report */
      1, $my_ssrc);

    $rtcp .= pack('CCnNa4NnnN',
      0x80,/* */
      204, /* RTCP_APP */
      4, $my_ssrc, // 3 is the number of dwords in this packet, - 1
      'qtak',
      $this->last_ssrc,
      0,
      $first_seqno & 0xFFFF,
      $seqno_mask);
    return $rtcp;
  }
};
