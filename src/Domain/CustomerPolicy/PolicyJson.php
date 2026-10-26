<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerPolicy;

/** Separate bounded policy codec; ordinary operation/quote codecs retain their bounds. */
final class PolicyJson {
    public const MAX_BYTES = 3145728;
    public const MAX_DEPTH = 24;
    public const MAX_NODES = 262144;

    public static function encode(array|\stdClass $value, int $maximum=self::MAX_BYTES): string {
        try {
            self::bound($maximum); $nodes=0; $normalized=self::normalize($value,0,$nodes);
            $json=json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(strlen($json)>$maximum){self::fail();} return $json;
        } catch(\Throwable){self::fail();}
    }
    public static function decode(string $json,int $maximum=self::MAX_BYTES): \stdClass|array {
        try {
            self::bound($maximum);if(''===$json||strlen($json)>$maximum){self::fail();}
            $value=json_decode($json,false,self::MAX_DEPTH+2,JSON_THROW_ON_ERROR);
            if(!is_array($value)&&!$value instanceof \stdClass){self::fail();}
            $nodes=0;self::normalize($value,0,$nodes);$offset=0;self::scan_duplicates($json,$offset);return $value;
        } catch(\Throwable){self::fail();}
    }
    public static function fields(mixed $value,array $fields):array {
        if($value instanceof \stdClass && \stdClass::class===get_class($value)){$value=get_object_vars($value);}
        if(!is_array($value)||count($value)!==count($fields)||[]!==array_diff($fields,array_keys($value))){self::fail();}
        return $value;
    }
    public static function list(mixed $value,int $maximum,int $minimum=0):array {
        if(!is_array($value)||!array_is_list($value)||count($value)<$minimum||count($value)>$maximum){self::fail();}return $value;
    }
    public static function integer(mixed $value,int $minimum=1):int {
        if(!is_int($value)||$value<$minimum){self::fail();}return $value;
    }
    public static function uuid(mixed $value):string {
        if(!is_string($value)||1!==preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$value)){self::fail();}return $value;
    }
    public static function digest(mixed $value):string {
        if(!is_string($value)||1!==preg_match('/\A[0-9a-f]{64}\z/D',$value)){self::fail();}return $value;
    }
    public static function quantity(mixed $value):string {
        if(!is_string($value)||1!==preg_match('/\A[1-9][0-9]*\z/D',$value)||strlen($value)>strlen((string)PHP_INT_MAX)||(strlen($value)===strlen((string)PHP_INT_MAX)&&strcmp($value,(string)PHP_INT_MAX)>0)){self::fail();}return $value;
    }
    private static function normalize(mixed $value,int $depth,int &$nodes):mixed {
        if($depth>self::MAX_DEPTH||++$nodes>self::MAX_NODES){self::fail();}
        if(is_array($value)){
            if(array_is_list($value)){$out=[];foreach($value as$item){$out[]=self::normalize($item,$depth+1,$nodes);}return $out;}
            $out=new \stdClass();$keys=array_keys($value);foreach($keys as$key){if(!is_string($key)){self::fail();}}sort($keys,SORT_STRING);
            foreach($keys as$key){if(1!==preg_match('//u',$key)){self::fail();}$out->{$key}=self::normalize($value[$key],$depth+1,$nodes);}return $out;
        }
        if($value instanceof \stdClass){
            if(\stdClass::class!==get_class($value)){self::fail();}$out=new \stdClass();$members=get_object_vars($value);$keys=array_keys($members);sort($keys,SORT_STRING);
            foreach($keys as$key){if(1!==preg_match('//u',(string)$key)){self::fail();}$out->{(string)$key}=self::normalize($members[$key],$depth+1,$nodes);}return $out;
        }
        if(is_string($value)){if(1!==preg_match('//u',$value)){self::fail();}return $value;}
        if(null===$value||is_bool($value)||is_int($value)){return $value;}self::fail();
    }
    private static function bound(int $value):void{if($value<2||$value>self::MAX_BYTES){self::fail();}}
    private static function fail():never{throw new \InvalidArgumentException('Invalid or oversized customer policy JSON.');}
	/** JSON already passed its parser; this preserves duplicate-key rejection. */
	private static function scan_duplicates( string $json, int &$offset ): void {
		self::skip_space( $json, $offset );
		$kind = $json[ $offset ];
		if ( '{' === $kind ) {
			++$offset;
			$seen = [];
			self::skip_space( $json, $offset );
			while ( '}' !== $json[ $offset ] ) {
				$start = $offset;
				self::skip_string( $json, $offset );
				$key = json_decode( substr( $json, $start, $offset - $start ), true, 2, JSON_THROW_ON_ERROR );
				$marker = ':' . $key;
				if ( isset( $seen[ $marker ] ) ) {
					throw new \InvalidArgumentException();
				}
				$seen[ $marker ] = true;
				self::skip_space( $json, $offset );
				++$offset; // Colon, validated by json_decode.
				self::scan_duplicates( $json, $offset );
				self::skip_space( $json, $offset );
				if ( ',' !== $json[ $offset ] ) {
					break;
				}
				++$offset;
				self::skip_space( $json, $offset );
			}
			++$offset;
		} elseif ( '[' === $kind ) {
			++$offset;
			self::skip_space( $json, $offset );
			while ( ']' !== $json[ $offset ] ) {
				self::scan_duplicates( $json, $offset );
				self::skip_space( $json, $offset );
				if ( ',' !== $json[ $offset ] ) {
					break;
				}
				++$offset;
			}
			++$offset;
		} elseif ( '"' === $kind ) {
			self::skip_string( $json, $offset );
		} else {
			$length = strlen( $json );
			while ( $offset < $length && ! str_contains( " \t\r\n,]}", $json[ $offset ] ) ) {
				++$offset;
			}
		}
	}

	private static function skip_string( string $json, int &$offset ): void {
		++$offset;
		while ( '"' !== $json[ $offset ] ) {
			if ( '\\' === $json[ $offset ] ) {
				++$offset;
			}
			++$offset;
		}
		++$offset;
	}

	private static function skip_space( string $json, int &$offset ): void {
		$length = strlen( $json );
		while ( $offset < $length && str_contains( " \t\r\n", $json[ $offset ] ) ) {
			++$offset;
		}
	}
}
