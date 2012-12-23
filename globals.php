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

// Change these as you like
define('MY_OS',        'GNULinux');
define('MY_OSVERSION', 'Fedora 12');

define('USER_AGENT',   'rtspdump-php v2.6');

// Accept packet loss if more than 400 packets have
// been received since the missing packet was noticed
define('RETRANSMIT_GIVEUP_INTERVAL', 400);
// If a retransmit request was fruitless, repeat the request
// after more than 100 packets have been received
define('RETRANSMIT_RETRY_INTERVAL',  100);
define('RETRANSMIT_RETRY_INTERVAL_SMALLER_MARGINAL',  3);
define('RETRANSMIT_RETRY_INTERVAL_GREATER_MARGINAL', 10);
// For help on the buffer length, see --help.
// If you change this value, change also the --help text.
define('DEFAULT_BUFFER_LENGTH', 50);

/* Debug bitmasks. If you change these, change the --help page too. */
define('DEBUG_RTSP',           1);
define('DEBUG_RTP_INCOMING',   2);
define('DEBUG_RTP_REORDERING', 4);
define('DEBUG_RTP_AV',         8);
define('DEBUG_MISC',          16);

$stream      = '';
$streams     = Array();
$output_file = 'dump.wmv';

$run_for  = 999999999; // some 31.689 years
$begin_offset_seconds = 0;
$rtx_port = 0;
$av_port  = 0;
$buffer_length_in_packets = DEFAULT_BUFFER_LENGTH;
$debug   = 0;
$verbose = 0;

// These variables are automatically deduced from server:
$rtsp_timeout     = 60; // Maximum mandatory interval for keep-alives
$asf_header       = ''; // Binary string for asf file header
$maxps            = 1600; // Maximum ASF packet size, all are padded to this
// These variables are used in RTSP traffic, automatically deduced from server:
$audio_seq = 1;
$video_seq = 1;
$first_seq = 1;
// These are my keys (should be randomized):
$my_ssrc_for_rtcp_nacks = rand(0,0x7FFFFFFF);
// Statistics:
$n_retransmit_requested = 0;
$n_retransmit_received  = 0;
$n_dropped       = 0;
$n_mm_packets    = 0;
$n_rtp_packets   = 0;
$n_mm_keyframes  = 0;
$n_mm_bytes      = 0;
$n_net_bytes     = 0;
