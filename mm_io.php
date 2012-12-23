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

class PacketConstruct_Default
{
  var $fp;
  var $streamcfg;

  function __construct(&$streamcfg, $fn)
  {
    $this->fp        = fopen($fn, 'w');
    $this->streamcfg = &$streamcfg;
  }

  function __destruct()
  {
    fclose($this->fp);
  }

  function DoPacket($rtppacket)
  {
    fwrite($this->fp,
      $rtppacket[1] /* payload */
          );
  }
};

class PacketConstruct_MPEG4_GENERIC extends PacketConstruct_Default // AAC
{
  var $sent_header;

  function __construct(&$streamcfg, $fn)
  {
    parent::__construct($streamcfg, $fn);
    $this->sent_header = false;
  }

  function DoPacket($rtppacket)
  {
    global $n_mm_packets, $n_mm_bytes, $debug;

    $payload = $rtppacket[1];

    $tmp = unpack('nnheaderbits', $payload);
    extract($tmp);
    $bitpos = 16;
    $sizelength  = (int)$this->streamcfg['sizelength'];
    $indexlength = (int)$this->streamcfg['indexlength'];
    $n_headers = (int)($nheaderbits / ($sizelength + $indexlength));
    $data_begin = 2 + (($nheaderbits + 7) >> 3);
    for($i=0; $i<$n_headers; ++$i)
    {
      $size  = GetBitsLong($payload, $bitpos, $sizelength);
      $index = GetBitsLong($payload, $bitpos, $indexlength);

      if($debug & DEBUG_RTP_AV)
      {
        printf("AAC: Writing packet %d/%d, (size=%d, index=%d)\n",
          $i+1, $n_headers, $size, $index);
      }

      $this->SavePacket(substr($payload, $data_begin, $size));

      $data_begin += $size;
      $n_mm_bytes += $size;
      ++$n_mm_packets;
    }
    if($debug & DEBUG_RTP_AV)
      printf("AAC: %d header bits in %d->%d bytes -> %d headers of %d+%d bits, ts=%d\n",
        $nheaderbits, strlen($payload), $data_begin,
        $n_headers, $sizelength, $indexlength,
        $rtppacket[0]['timestamp']);
  }

  private function SavePacket($packet)
  {
    #if(!$this->sent_header)
    {
      $this->sent_header = true;
      #$rate     = $this->streamcfg['param1'];
      #$channels = $this->streamcfg['param2'];
      $cfgstr   = $this->streamcfg['config'];
      $cfg = '';
      for($c = 0; $c < strlen($cfgstr) || $c < 12; $c += 2)
        $cfg .= chr(hexdec(substr($cfgstr, $c, 2)));
      $bitpos = 0;
      $objtype = GetBitsLong($cfg,$bitpos,5);
      if($objtype == 28) $objtype = GetBitsLong($cfg,$bitpos,6);
      $sampling_frequency_index = GetBitsLong($cfg,$bitpos,4);
      if($sampling_frequency_index == 15)
        $sampling_frequency_index = strpos(
          "96000 88200 64000 48000 44100 32000 24000 22050 16000 ".
          "12000 11025 8000  7350", GetBitsLong($cfg,$bitpos,24)) / 5;
      $chan_config = GetBitsLong($cfg,$bitpos,4);
      $header = '';
      $bitpos = 0;
      PutBitsLong($header,$bitpos, 0xFFF, 12); // adts syncword
      PutBitsLong($header,$bitpos, 1, 1); // mpeg4
      PutBitsLong($header,$bitpos, 0, 2); // layer 0
      PutBitsLong($header,$bitpos, 1, 1); // protection absent
      PutBitsLong($header,$bitpos, $objtype-1, 2); // profile_objecttype
      PutBitsLong($header,$bitpos, $sampling_frequency_index, 4);
      PutBitsLong($header,$bitpos, 0, 1); // private
      PutBitsLong($header,$bitpos, $chan_config, 3);
      PutBitsLong($header,$bitpos, 0, 4); // original/copy, home, I, T
      PutBitsLong($header,$bitpos, 7+strlen($packet), 13); // frame length
      PutBitsLong($header,$bitpos, 0x7FF, 11); // adts buffer fullness
      PutBitsLong($header,$bitpos, 0, 2); // number_of_raw_data_blocks_in_frame
      // ^ AAC decoder in ffmpeg does not support multiple AAC frames per ADTS
      // ^ libfaad2 ignores this value alltogether
      fwrite($this->fp, $header);
    }
    fwrite($this->fp, $packet);
    fflush($this->fp);
  }
}; // MPEG4-GENERIC

class PacketConstruct_H264 extends PacketConstruct_Default // H.264
{
  var $nal;
  var $interleaved;
  var $seen_mm_keyframe;

  function __construct(&$streamcfg, $fn)
  {
    parent::__construct($streamcfg, $fn);
    $this->nal = '';

    $this->interleaved = $streamcfg['packetization-mode'] == 2;

    foreach(explode(',', $streamcfg['sprop-parameter-sets']) as $prop)
    {
      fwrite($this->fp, "\0\0\1");
      fwrite($this->fp, base64_decode($prop));
    }
    fflush($this->fp);

    $this->seen_mm_keyframe = false;
  }

  function DoPacket($rtppacket)
  {
    global $debug;

    $payload = $rtppacket[1];

    $b0 = ord($payload[0]);
    $b1 = ord($payload[1]);

    $don = null;
    switch($b0 & 0x1F)
    {
      case 25: // STAP-B
        $tmp = unpack('ndon', substr($payload, 1)); extract($tmp);
        $b0 = ($b0 & 0xE0) | 24; // Change to STAP-A
        break;
      case 29: // FU-B
        $tmp = unpack('ndon', substr($payload, 2)); extract($tmp);
        $b0 = ($b0 & 0xE0) | 28; // Change to FU-A
        break;
    }
    switch($b0 & 0x1F)
    {
      case 24: // STAP-A (one packet, multiple nals)
        $pos = $don === null ? 1 : 3;
        $end = strlen($payload);
        while($pos < $end)
        {
          $tmp = unpack('nlength', substr($payload, $pos));
          $length = $tmp['length'];
          $pos += 2;
          if($pos + $length > $end) break;
          $packet = substr($payload, $pos, $length);
          if($debug & DEBUG_RTP_AV)
            printf("H264: Multi-NAL packet (%d bytes, type %02X)\n",
              strlen($packet), ord($packet[0]));
          $this->PutPacket($packet, $don);
          $pos += $length;
        }
        if($debug & DEBUG_RTP_AV)
          printf("H264: End multi-NAL packet (ts=%d%s)\n",
            $rtppacket[0]['timestamp'],
            $don===null ? '' : sprintf(', DON=%04X', $don));
        break;
      case 26: case 27: // MTAP16, MTAP24
        // 26= 16-bit DON --> list of MTAPs
        //                     each MTAP = 16-bit length
        //                                 8-bit DON offset
        //                                 16-bit TS offset
        //                                 NAL unit
        // 27= 16-bit DON --> list of MTAPs
        //                     each MTAP = 16-bit length
        //                                 8-bit DON offset
        //                                 24-bit TS offset
        //                                 NAL unit
        $tmp = unpack('ndon', substr($payload, 1)); extract($tmp);
        $pos = 3;
        $tsshift  = ($b0 & 0x1F) == 26 ? 16 : 8;
        $hdrsize  = ($b0 & 0x1F) == 26 ?  5 : 6;
        $end = strlen($payload);
        while($pos < $end)
        {
          $tmp = unpack('nlength/Cdond/Ntsoffset', substr($payload, $pos));
          $length = $tmp['length'];
          $pos += $hdrsize;
          if($pos + $length > $end) break;
          $packet = substr($payload, $pos, $length);
          if($debug & DEBUG_RTP_AV)
            printf("H264: Multi-NAL multi-time packet (ts=%d, %d bytes, type %02X, DON=%04X)\n",
              $rtppacket[0]['timestamp'] + ($tmp['tsoffset'] >> $tsshift),
              strlen($packet), ord($packet[0]), $don + $dond);
          $this->PutPacket($packet, $don + $dond);
          $pos += $length;
        }
        if($debug & DEBUG_RTP_AV)
          printf("H264: End multi-NAL multi-time packet\n");
        break;
      case 28: // FU-A (partial packet)
        $startbit = $b1 & 0x80;
        $endbit   = $b1 & 0x40;
        $pos = $don === null ? 2 : 4;
        if($startbit)
        {
          $this->nal = chr( ($b0 & 0xE0) | ($b1 & 0x1F) );
          if($debug & DEBUG_RTP_AV)
            printf("H264: Begin multi-packet NAL  (ts=%d, %d bytes, type %02X%s)\n",
              $rtppacket[0]['timestamp'],
              strlen($payload)-$pos+1, ord($this->nal[0]),
              $don===null ? '' : sprintf(', DON=%04X', $don));
        }
        else if(!$startbit && !$endbit)
        {
          if($debug & DEBUG_RTP_AV)
            printf("H264: Middle multi-packet NAL (ts=%d, %d bytes, type %02X%s)\n",
              $rtppacket[0]['timestamp'],
              strlen($payload)-$pos, ord($this->nal[0]),
              $don===null ? '' : sprintf(', DON=%04X', $don));
        }
        $this->nal .= substr($payload, $pos);
        if($endbit)
        {
          if($debug & DEBUG_RTP_AV)
            printf("H264: Finish multi-packet NAL (ts=%d, %d->%d bytes, type %02X%s)\n",
              $rtppacket[0]['timestamp'],
              strlen($payload)-$pos, strlen($this->nal), ord($this->nal[0]),
              $don===null ? '' : sprintf(', DON=%04X', $don));
          $this->PutPacket($this->nal, $don);
        }
        break;
      default: // complete packet
        if($debug & DEBUG_RTP_AV)
          printf("H264: Single-NAL packet (ts=%d, %d bytes, type %02X)\n",
            $rtppacket[0]['timestamp'],
            strlen($payload), $b0);
        $this->PutPacket($payload);
    }
  }

  function PutPacket(&$nal, $don = null)
  {
    // TODO: Handle $don if $this->interleaved
    global $n_mm_packets, $n_mm_bytes;

    if($nal[0] == chr(37)) $this->seen_mm_keyframe = true;

    /* For A/V sync, ignore keyframe problems */
    #if($this->seen_mm_keyframe)
    {
      fwrite($this->fp, "\0\0\1" . $nal);
      fflush($this->fp);
      $n_mm_packets += 1;
      $n_mm_bytes   += strlen($nal) + 3;
    }
    $nal = '';
  }
}; // H264 -- references: RFC 3984

class PacketConstruct_X_ASF_PF extends PacketConstruct_Default
{
  var $asfpacket;
  var $last_seqno;
  var $resyncing;

  function __construct(&$streamcfg, $fn)
  {
    global $asf_header;

    parent::__construct($streamcfg, $fn);

    fwrite($this->fp, $asf_header);

    $this->last_seqno = 0;
    $this->asfpacket  = '';

    /* Microsoft's RTSP specification explicitly states
     * that the first packet to be received is a keyframe.
     *
     * However, we cannot know whether the initial packet
     * actually failed to transmit, so we have to use the resync
     * mechanism. Let's just hope that the first packet the
     * server sends is a complete ASF packet (i.e. mark & length),
     * so we won't miss anything.
     */
    $this->resyncing  = true;
  }

  function DoPacket($rtppacket)
  {
    global $n_mm_packets, $n_mm_keyframes, $n_mm_bytes;
    global $debug, $maxps;

    $tmp2        = $rtppacket[0];
    $mark        = $tmp2['mark'];
    $payloadtype = $tmp2['payloadtype'];
    $rtp_payload = $rtppacket[1];

    /* The payload begins with a special prefix, followed by
     * an ASF data packet piece. We want an ASF data packet,
     * so we can put it into the file.
     */
    $asf_hdr_bits = ord($rtp_payload[0]);
    $asf_payload_begin_offset = 4;
    if($asf_hdr_bits & 0x20) $asf_payload_begin_offset += 4; // skip relative timestamp
    if($asf_hdr_bits & 0x10) $asf_payload_begin_offset += 4; // skip duration
    if($asf_hdr_bits & 0x08) $asf_payload_begin_offset += 4; // skip locationid

    $format = ($asf_hdr_bits & 0x40) ? 'Nlength' : 'Noffset';
    if($asf_hdr_bits & 0x20) $format .= '/Nrelativetimestamp';
    if($asf_hdr_bits & 0x10) $format .= '/Nduration';
    if($asf_hdr_bits & 0x08) $format .= '/Nlocationid';

    $seqno = $tmp2['seqno'];

    $tmp2['ssrc']  = sprintf('%X', $tmp2['ssrc']);
    $tmp2['seqno'] = sprintf('%X', $tmp2['seqno']);
    $tmp2['asfhdrbits'] = $asf_hdr_bits;
    $tmp = unpack($format, $rtp_payload);
    $tmp = array_merge($tmp2, $tmp);
    $tmp['payloadlength'] = strlen($rtp_payload);
    unset($tmp2);

    if($asf_hdr_bits & 0x40) $tmp['length'] &= 0xFFFFFF;
    else                     $tmp['offset'] &= 0xFFFFFF;

    if($debug & DEBUG_RTP_AV)
    {
      unset($tmp['host']);
      unset($tmp['port']);
      $s = 'ASF packet';
      foreach($tmp as $k=>$v) $s .= ", $k=$v";
      printf("%s\n", $s);
    }

    $is_sync_packet = ($asf_hdr_bits & 0x40) || ($tmp['offset'] == 0);

    if($this->resyncing)
    {
      /* To resync, we need
       *   A) a packet with length field (indicated with hdr bit 0x40)
       *       - it's a complete packet, that we can write to file
       *   B) a packet with offset field = 0
       *       - it's the beginning of a packet
       */
      if($is_sync_packet)
      {
        if($debug & DEBUG_RTP_AV)
          printf("ASF: Resync successful\n");
        $this->resyncing = false;
        $this->asfpacket = '';
      }
      else
      {
        if($debug & DEBUG_RTP_AV)
          printf("ASF: Ignoring packet\n");
        return;
      }
    }
    else
    {
      if(($seqno & 0xFFFF)
      != (($this->last_seqno+1) & 0xFFFF))
      {
        if(!$is_sync_packet)
        {
          if($debug & DEBUG_RTP_AV)
            printf("ASF: Mismatched seqno. Dropping %d bytes of payload. Resyncing... Ignoring packet.\n",
              strlen($this->asfpacket));
          $this->resyncing = true;
          return;
        }
        if($debug & DEBUG_RTP_AV)
          printf("ASF: Mismatched seqno. Dropping %d bytes of payload. Resyncing... Resync successful.\n",
            strlen($this->asfpacket));
        $this->asfpacket = '';
      }
    }

    $this->last_seqno = $seqno & 0xFFFF;

    if($asf_hdr_bits & 0x40) // 'length'
    {
      if($tmp['length'] != $tmp['payloadlength'])
      {
        if($debug & DEBUG_RTP_AV)
          printf("ASF: Length %d is wrong. Should be %d. Using %d, and continuing.\n",
            $tmp['length'], $tmp['payloadlength'], $tmp['payloadlength']);
      }

      if(strlen($this->asfpacket) > 0)
      {
        if($debug & DEBUG_RTP_AV)
          printf("ASF: Length %d is unexpected. Dropping existing %d bytes of payload. But continuing.\n",
            $tmp['payloadlength'], strlen($this->asfpacket));
        $this->asfpacket = '';
      }

      if(!$mark)
      {
        if($debug & DEBUG_RTP_AV)
          printf("ASF: An unmarked 'length' packet is an abomination. But continuing.\n");
      }
    }
    else // 'offset'
    {
      if($tmp['offset'] != strlen($this->asfpacket))
      {
        if(!$is_sync_packet)
        {
          if($debug & DEBUG_RTP_AV)
            printf("ASF: Offset %d is wrong. Dropping %d bytes of payload. Resyncing... Ignoring packet.\n",
              $tmp['offset'], strlen($this->asfpacket));
          $this->resyncing = true;
          return;
        }
        else
        {
          if($debug & DEBUG_RTP_AV)
            printf("ASF: Offset %d is wrong. Dropping %d bytes of payload. Resyncing... Resync successful.\n",
              $tmp['offset'], strlen($this->asfpacket));
          $this->asfpacket = '';
        }
      }
    }

    $this->asfpacket .= substr($rtp_payload, $asf_payload_begin_offset);

    if($mark && strlen($this->asfpacket))
    {
      // packet_finished
      $this->asfpacket = str_pad($this->asfpacket, $maxps, chr(0));

      #printf("ASF: Writing %d\n", strlen($this->asfpacket));

      if($asf_hdr_bits & 0x80)
        ++$n_mm_keyframes;

      $n_mm_bytes += strlen($this->asfpacket);

      fwrite($this->fp, $this->asfpacket);
      fflush($this->fp);

      ++$n_mm_packets;
      $this->asfpacket = ''; // A new ASF packet begins here
    }
  }
}; // X-ASF-PF
