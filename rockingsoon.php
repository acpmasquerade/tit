<?php

    /** 
    
    Get the Rocking Soon template for supplied postdata 
    Allowed vars
    
    1. logo
    2. tagline
    3. footer
    
    Returns object as follows
        
        1. object->header 
        2. object->footer
    
    **/
    
    function rocking_soon($postdata){
    
        // The rocking soon API endpoint

		error_reporting(E_ALL);

        $url = "http://116.90.235.66/rockingsoon.alternate/api.php";
        $ch = curl_init();

        // set the target url
        curl_setopt($ch, CURLOPT_URL,$url);

        // howmany parameter to post
        curl_setopt($ch, CURLOPT_POST, 1);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		if(!is_array($postdata)){
			if(is_object($postdata)){
				// change to array
				$_postdata = array();
				foreach($postdata as $k=>$v){
					$_postdata[$k] = $v;
				}
				$postdata = $_postdata;
			}else{
				// might be some non array, non object, make that
				$postdata = array($postdata);
			}
		}

		/**
			RockingSoon	ALLOWED KEYS
		**/

		$RS_ALLOWED_KEYS = array("footer", "logo", "tagline", "bottomline");


		foreach($postdata as $key=>$value){
			// check allowed vars
			if(in_array($key, $RS_ALLOWED_KEYS)){
				//$postdata[$key] = urlencode($value);
			}else{
				unset($postdata[$key]);
			}
		}

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        
        $result= curl_exec ($ch);

        curl_close ($ch);

        $t = json_decode($result);

        return $t;

    }


/** End of file **/
