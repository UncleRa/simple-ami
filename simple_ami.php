<?php
/*
Description	: Classes for sending SMS through the OpenVox gateway
              and making calls through the Asterisk
Author      : Rozes "Ramzes" Alexander
Date        : 2017-05-19
Usage       : See below...
*/

class SimpleAMI
{
  public    $username = 'admin'    ;
  public    $password = 'admin'    ;
  public    $ip       = '127.0.0.1';
  public    $port     = 5038       ;

  protected $is_login = false      ;

  private   $sock     = null       ;
  private   $is_debug = false      ;

  const   EOL1      = "\r\n"     ;
  const   EOL2      = "\r\n\r\n" ;

  function __construct( $ip = '', $port = '', $username = '', $password = '' )
  {
    if ( !empty( $ip       ) ) $this->ip       = $ip      ;
    if ( !empty( $port     ) ) $this->port     = $port    ;
    if ( !empty( $username ) ) $this->username = $username;
    if ( !empty( $password ) ) $this->password = $password;
  }

  function __destruct()
  {
    if ( $this->is_login ) $this->logout();
  }
  
  public function setDebug( $debug )
  {
    ( $debug ) ? $this->is_debug = true : $this->is_debug = false;
  }

  public function pingAsterisk()
  {
    if ( !$this->is_login ) return false;
    $msg  = "Action: Ping" . self::EOL2;
    $resp = $this->get_response( $msg );
    if ( !empty( $resp ) && explode( ' ', explode( self::EOL1, $resp )[1] )[1] == 'Pong' ) return true;
    return false;
  }

  public function login()
  {
    if ( !$this->sock = socket_create( AF_INET, SOCK_STREAM, SOL_TCP ) )
      throw new Exception( "socket_create() error: " . socket_strerror(socket_last_error()) );
    if ( !$conn = socket_connect( $this->sock, $this->ip, $this->port ) )
      throw new Exception( "socket_connect() error: " . socket_strerror(socket_last_error()) );
    $msg        = "Action: Login" . self::EOL1;
    $msg       .= "Username: "    . $this->username . self::EOL1;
    $msg       .= "Secret: "      . $this->password . self::EOL1;
    $msg       .= "Events: off"   . self::EOL2;
    $resp       = $this->get_response( $msg );
    if ( !empty( $resp ) && explode( ' ', explode( self::EOL1, $resp )[2] )[2] == 'accepted' )
    {
      $this->is_login = true;
      return true;
    }
    else socket_close( $this->sock );
    return false;
  }

  private function logout()
  {
    if ( $this->is_login )
    {
      $msg  = "Action: Logoff" . self::EOL2;
      $resp = $this->get_response( $msg );
      if ( !empty( $resp ) && explode( ' ', explode( self::EOL1, $resp )[0] )[1] == 'Goodbye' ) socket_close( $this->sock );
    }
  }

  protected function get_response( $request )
  {
    $retval = '';
    if ( !$reqv = socket_write( $this->sock, $request ) )
      throw new Exception( "socket_write() error: " . socket_strerror(socket_last_error()) );
    while ( true )
    {
      if ( !$byte = socket_recv( $this->sock, $resp, 1, MSG_WAITALL ) )
        throw new Exception( "socket_recv() error: " . socket_strerror(socket_last_error()) );
      $retval .= $resp;
      if ( strlen( $retval ) > 4 && substr( $retval, -4 ) == self::EOL2 ) break;
    }
    if ( $this->is_debug ) echo $retval;
    return $retval;
  }
}

class OpenVoxSMS extends SimpleAMI
{
  public function sendSMS( $phone, $letter, $sync = 'sync', $span = 1, $timeout = 30 )
  {
    if ( !$this->is_login ) return false;
    $chunk     = 160;
    $chunk_dec = 8  ;
    if ( mb_detect_encoding( $letter ) != "ASCII" )
    { $chunk     = 70;
      $chunk_dec = 3 ;
      $letter    = mb_convert_encoding( $letter, "UTF-8", mb_detect_encoding( $letter ) );
    }
    if ( strlen( $letter ) <= $chunk )
    {
      $msg  = "Action: Command" . self::EOL1;
      $msg .= "Command: gsm send " . $sync . " sms " . $span . " " . $phone . " \"" . addslashes( $letter ) . "\" " . $timeout . self::EOL2;
      $resp = $this->get_response( $msg );
      if ( !empty( $resp ) && !$sync ) return true;
      if ( !empty( $resp ) && explode( ' ', explode( PHP_EOL, $resp )[2] )[5] == 'SUCCESSFULLY' ) return true;
      return false;
    }
    $i      = 0;
    $chunk -= $chunk_dec;
    $arr    = array();
    while ( $i < strlen( $letter ) )
    {
        $arr[] =  mb_strcut( $letter, $i, $chunk );
        $i += $chunk;
    }
    foreach ( $arr as $i => $item )
    {
      $msg  = "Action: Command" . self::EOL1;
      $msg .= "Command: gsm send sync csms " . $span . " " . $phone . " \"" . addslashes( $item ) . "\" ";
      $msg .= "00 " . count( $arr ) . " " . ($i + 1) . " " . $timeout . self::EOL2;
      $resp = $this->get_response( $msg );
      if ( empty( $resp ) || explode( ' ', explode( PHP_EOL, $resp )[2] )[6] != 'SUCCESSFULLY' ) return false;
    }
    return true;
  }
}

class AsteriskCall extends SimpleAMI
{
  public function makeCall( $channel                           ,
                            $exten                             ,
                            $callerid = '"Asterisk" <Asterisk>',
                            $answer   = false                  ,
                            $context  = 'from-internal'        ,
                            $priority = '1'                    )
  {
    if ( !$this->is_login ) return false;
    $msg  = "Action: Originate"      . self::EOL1;
    $msg .= "Channel: "  . $channel  . self::EOL1;
    $msg .= "Context: "  . $context  . self::EOL1;
    $msg .= "Exten: "    . $exten    . self::EOL1;
    $msg .= "Priority: " . $priority . self::EOL1;
    $msg .= "Callerid: " . $callerid . self::EOL1;
    $msg .= "Account: SITE"          . self::EOL1;
    if ( $answer ) $msg .= 'Variable: SIPADDHEADER="Call-Info:\;answer-after=0"' . self::EOL1;
    $msg .= self::EOL1;
    $resp = $this->get_response( $msg );
    return $resp;
  }
}

// Samples of usage

// Simple connect and ping Asterisk
/*
try {
  $test = new SimpleAMI( '172.16.99.1' );
  //$test->setDebug( true );
  $test->login();
  echo ( $test->pingAsterisk() ) ? "Response: Ok" . PHP_EOL : "Response: Fail" . PHP_EOL;
} catch ( Exception $e ) {
    echo "Something is wrong: " . $e->getMessage() . PHP_EOL;
}
*/

// Send SMS
/*
try {
  $test = new OpenVoxSMS( '172.16.99.1' );
  $test->setDebug( true );
  $test->login();
  $sms = "Текст может состоять из алфавитно-цифровых символов. Максимальный размер сообщения в стандарте GSM — 140 байт (1120 бит).";
  //$sms = "Messages are sent with the MAP MO- and MT-ForwardSM operations, whose payload length is limited by the constraints of the signaling protocol to precisely 140 bytes (140 bytes * 8 bits / byte = 1120 bits).";
  $test->sendSMS('89100000000', $sms);
} catch ( Exception $e ) {
    echo "Something is wrong: " . $e->getMessage() . PHP_EOL;
}
*/

// Make call
/*
try {
  $test = new AsteriskCall( '192.168.0.1', '', 'admin', 'p@s$w0rD' );
  $test->setDebug( true );
  $test->login();
  $test->makeCall( 'SIP/100', '89100000000', '"Asterisk"<100>' );
} catch ( Exception $e ) {
    echo "Something is wrong: " . $e->getMessage() . PHP_EOL;
}
*/
?>
