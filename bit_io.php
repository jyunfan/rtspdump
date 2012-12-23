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

function GetBits($buffer,&$bitpos, $n)
{
  $p = unpack('Ncache', substr($buffer, $bitpos>>3, 4));
  $cache = $p['cache'] << ($bitpos & 7);
  $tmp = ($cache >> (32-$n)) & ((1 << $n)-1);
  //print "at $bitpos, got {$p['cache']}, shifted to $cache, result $tmp\n";
  $bitpos += $n;
  return $tmp;
}
function GetBitsLong($buffer,&$bitpos, $nbits)
{
  if($nbits <= 17) return GetBits($buffer,$bitpos,$nbits);
  $result = GetBits($buffer,$bitpos,16) << ($n-16);
  $result |= GetBits($buffer,$bitpos,$n-16);
  return $result;
}
function PutBitsLong(&$buffer,&$bitpos, $value, $nbits)
{
  while($nbits > 0)
  {
    if($bitpos >= strlen($buffer)*8) $buffer .= chr(0);
    $byte = ord($buffer[$bitpos >> 3]);

    $n_eat_bits = min($nbits, 8 - ($bitpos & 7));
    $remain_bits = $nbits - $n_eat_bits;

    #$ate_bits = $value & ((1 << ($n_eat_bits)) - 1);
    #$value >>= $n_eat_bits;
    $ate_bits = $value >> $remain_bits;
    $value &= ((1 << ($remain_bits)) - 1);

    #$byte |= $ate_bits << (8-$n_eat_bits-($bitpos & 7));
    $byte = ($byte << $n_eat_bits) | $ate_bits;
    $buffer[$bitpos >> 3] = chr($byte);

    $nbits  = $remain_bits;
    $bitpos += $n_eat_bits;
  }
}
