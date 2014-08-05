<?php
	ini_set("max_execution_time",120);

	//Database connection info
	$db_hostname = "";	//Hostname
	$db_port = 3306;		//Port
	$db_username = "";		//Username
	$db_password = "";	//Password
	$db_database = "";	//Database

	//Steam info
	$account_invites = 100;		//Number of invites per account per hour -- DO NOT CHANGE IT
	$group_invites = 50;		//Number of invites per two hours per group -- DO NOT CHANGE IT
	$ip_invites = 25000;		//Number of invites per two hours per ip -- DO NOT CHANGE IT

	$ip_time = 60*60*2;
	$ac_time = 60*60*2;
	$gr_time = 60*60*2;

	$user_agent = "Mozilla/5.0 (Windows; U; Windows NT 6.1; rv:2.2) Gecko/20110201";

	//-------------- DO NOT CHANGE ANYTHING BELOW THIS LINE --------------

	$steam_cookie = "";

	$ifconfig = array();
	$ips = array();
	exec("/sbin/ifconfig", $ifconfig);
	$ifconfig = implode("\n", $ifconfig);
	preg_match_all("/inet addr:([^\ ]*) ([^\n]*)\n([ ]*)UP/i", $ifconfig, $matches);
	for($i=0;$i<count($matches[1]);++$i)
		if(!preg_match("/127\.0\.0\.[\d]/i", $matches[1][$i]))
			$ips[]=$matches[1][$i];

	//Connect to database
	$mysqli_conn = mysql_pconnect($db_hostname.":".$db_port, $db_username, $db_password);
	mysql_select_db($db_database);
	

	$server_row = mysql_fetch_assoc(mysql_query("SELECT groupids FROM server_groups WHERE serverip=\"".$_GET["serverip"]."\" AND active=1"));

	$plugin_groupid = explode(",", $server_row["groupids"]);

	if(count($plugin_groupid) == 0)
	{
		if($_GET["debug"])
		{
			echo "Server is not present in the database";
		}
		die();
	}

	$invites_now = 0;

	for($i=0;$i<count($plugin_groupid);++$i)
	{
		if(file_exists("autoinvite.txt"))
		{
			$steam_cookie = file_get_contents("autoinvite.txt");
		}

		$query = mysql_query("SELECT *, (SELECT COUNT(*) FROM invitations_sent WHERE accountid=accounts.id AND time > ".(time()-$ac_time).") FROM accounts WHERE active = 1 HAVING (SELECT COUNT(*) FROM invitations_sent WHERE accountid=accounts.id AND time > ".(time()-$ac_time).") < ".$account_invites);
		if(mysql_num_rows($query)==0)
		{
			if(is_numeric($_GET["communityid"]))
			{
				$server_row = mysql_fetch_assoc(mysql_query("SELECT groupids FROM server_groups WHERE serverip=\"".$_GET["serverip"]."\" AND active=1"));
				$plugin_groupid = explode(",", $server_row["groupids"]);
				foreach($plugin_groupid AS $groupid)
					if(!empty($groupid))
						if(mysql_num_rows(mysql_query("SELECT * FROM queued_invitations WHERE groupid=\"".$groupid."\" AND communityid=\"".$_GET["communityid"]."\"")) == 0 && strlen($_GET["communityid"])==17)
							mysql_query("INSERT INTO queued_invitations (communityid, groupid) VALUES(\"".mysql_real_escape_string($_GET["communityid"])."\", \"".$groupid."\")");
			}
			die("No accounts available");
		}
		$user = mysql_fetch_assoc($query);
		$ip = $user["lastip"];

		//Over the time limit, resetting
		if($steam_cookie == "" || ($user["cookie"] != "" && $user["cookie"]!=$steam_cookie) || $user["cookie"] == "" || !in_array($ip, $ips))
		{
			$ip = $ips[rand(0, count($ips)-1)];
			$data = array("username" => $user["username"]);

			$steam_login = curl_init();
			curl_setopt($steam_login, CURLOPT_URL, "http://steamcommunity.com/");
			curl_setopt($steam_login, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($steam_login, CURLOPT_HEADER, 1);
			//curl_setopt($steam_login, CURLOPT_INTERFACE, $ip);
			curl_setopt($steam_login, CURLOPT_USERAGENT, $user_agent);
			$output=curl_exec($steam_login);
			if($_GET["debug"])
				echo "Output: ".$output;

			if(preg_match("/sessionid=(.*);/i", $output, $sessionid))
				$sessionid = $sessionid[1];

			$steam_login = curl_init();
			curl_setopt($steam_login, CURLOPT_URL, "https://steamcommunity.com/login/home/");
			curl_setopt($steam_login, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($steam_login, CURLOPT_COOKIE, "sessionid=".$sessionid);
			curl_setopt($steam_login, CURLOPT_HEADER, 1);
			//curl_setopt($steam_login, CURLOPT_INTERFACE, $ip);
			curl_setopt($steam_login, CURLOPT_USERAGENT, $user_agent);
			curl_setopt($steam_login, CURLOPT_CAINFO, "./cacert.pem");
			$output=curl_exec($steam_login);

			$steam_login = curl_init();
			curl_setopt($steam_login, CURLOPT_URL, "https://steamcommunity.com/login/getrsakey/");
			curl_setopt($steam_login, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($steam_login, CURLOPT_HEADER, 1);
			curl_setopt($steam_login, CURLOPT_POST, 1);
			curl_setopt($steam_login, CURLOPT_POSTFIELDS, $data);
			//curl_setopt($steam_login, CURLOPT_INTERFACE, $ip);
			curl_setopt($steam_login, CURLOPT_COOKIE, "sessionid=".$sessionid);
			curl_setopt($steam_login, CURLOPT_REFERER, "https://steamcommunity.com/login/home/");
			curl_setopt($steam_login, CURLOPT_USERAGENT, $user_agent);
			curl_setopt($steam_login, CURLOPT_CAINFO, "./cacert.pem");
			$output=curl_exec($steam_login);
			if($_GET["debug"])
				echo "Output: ".$output;

			preg_match_all("/\:\"([^\"]*)\"/", $output, $matches);
			$rsa["mod"]=$matches[1][0];
			$rsa["exp"]=$matches[1][1];
			$rsa["time"]=$matches[1][2];

			include("Crypt/RSA.php");
			$RSA = new Crypt_RSA();
			$RSA->setEncryptionMode(CRYPT_RSA_ENCRYPTION_PKCS1);
			$n = new Math_BigInteger($rsa["mod"], 16);
			$e = new Math_BigInteger($rsa["exp"], 16);
			$key = array("modulus"=>$n,"publicExponent"=>$e);
			$RSA->loadKey($key, CRYPT_RSA_PUBLIC_FORMAT_RAW);
			$encryptedPassword = base64_encode($RSA->encrypt($user["password"]));

			//Fetching Steam cookie
			$data = array("username" => $user["username"],
				"password" => $encryptedPassword,
				"emailauth" => "",
				"emailsteamid" => "",
				"captchagid" => "-1",
				"captcha_text" => "",
				"rsatimestamp" => $rsa["time"]);

			print_r($data);

			$steam_login = curl_init();
			curl_setopt($steam_login, CURLOPT_URL, "https://steamcommunity.com/login/dologin/");
			curl_setopt($steam_login, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($steam_login, CURLOPT_COOKIE, "sessionid=".$sessionid);
			curl_setopt($steam_login, CURLOPT_HEADER, 1);
			curl_setopt($steam_login, CURLOPT_POST, 1);
			curl_setopt($steam_login, CURLOPT_POSTFIELDS, $data);
			//curl_setopt($steam_login, CURLOPT_INTERFACE, $ip);
			curl_setopt($steam_login, CURLOPT_USERAGENT, $user_agent);
			curl_setopt($steam_login, CURLOPT_CAINFO, "./cacert.pem");
			$output=curl_exec($steam_login);
			if($_GET["debug"])
				echo $output;

			if(preg_match("/steamLogin=(.*);/i", $output, $steam_cookie))
				$steam_cookie = $steam_cookie[1];
			curl_close($steam_login);

			if(is_array($steam_cookie))
			{
				if($_GET["debug"])
				{
					echo "<br/>Steam Authentication Failed -> ";
					print_r($user);
					echo "<br/><br/>";
				}
				die();
			}

			touch("autoinvite.txt");
			file_put_contents("autoinvite.txt", $steam_cookie);

			$steam_communityid = str_replace(strstr($steam_cookie, "%"), "", $steam_cookie);
			$token = substr(strstr(substr(strstr($steam_cookie, "%"), 1), "%"), 3);

			$steam_transfer = curl_init();
			curl_setopt($steam_transfer, CURLOPT_URL, "https://store.steampowered.com/login/transfer");
			curl_setopt($steam_transfer, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($steam_transfer, CURLOPT_HEADER, 1);
			curl_setopt($steam_transfer, CURLOPT_POST, 1);
			//curl_setopt($steam_transfer, CURLOPT_INTERFACE, $ip);
			curl_setopt($steam_transfer, CURLOPT_COOKIE, "steamLogin=".$steam_cookie."; sessionid=".$sessionid);
			curl_setopt($steam_transfer, CURLOPT_POSTFIELDS, array("steamid"=>$steam_communityid,"token"=>$token));
			curl_setopt($steam_transfer, CURLOPT_USERAGENT, $user_agent);
			curl_setopt($steam_transfer, CURLOPT_CAINFO, "./cacert.pem");
			$output=curl_exec($steam_transfer);
			if($_GET["debug"])
					echo $output;

			if(preg_match("/steamLogin=(.*);/i", $output, $browserid))
				$browserid = $browserid[1];

			$steam_transfer = curl_init();
			curl_setopt($steam_transfer, CURLOPT_URL, "http://steamcommunity.com/profiles/".$steam_communityid."/home");
			curl_setopt($steam_transfer, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($steam_transfer, CURLOPT_HEADER, 1);
			//curl_setopt($steam_transfer, CURLOPT_INTERFACE, $ip);
			curl_setopt($steam_transfer, CURLOPT_COOKIE, "steamLogin=".$steam_cookie."; browserid=".$browserid."; sessionid=".$sessionid);
			curl_setopt($steam_transfer, CURLOPT_USERAGENT, $user_agent);
			$output=curl_exec($steam_transfer);
			if($_GET["debug"])
					echo $output;

			mysql_query("UPDATE accounts SET cookie=\"".$steam_cookie."\", sessionid=\"".$sessionid."\", lastip=\"".$ip."\" WHERE id=".$user["id"]);
		}
		else
		{
			$sessionid = $user["sessionid"];
		}
		
		echo "logged in as ".$user["username"];

		$steam_communityid = str_replace(strstr($steam_cookie, "%"), "", $steam_cookie);
		$token = substr(strstr(substr(strstr($steam_cookie, "%"), 1), "%"), 3);

		if($plugin_groupid[$i]=="")
			continue;
		$invites_now = 0;
		$successful_invites = array();
		$query = mysql_query("SELECT locked FROM groups WHERE groupid=\"".$plugin_groupid[$i]."\" ");

		if(mysql_num_rows($query)==0)
		{
			mysql_query("INSERT INTO groups (groupid, locked) VALUES(\"".$plugin_groupid[$i]."\", 0)");
			$lock = false;
		}
		else
			list($lock) = mysql_fetch_array($query, MYSQL_NUM);

		$sofar_ip = mysql_num_rows(mysql_query("SELECT * FROM invitations_sent WHERE serverip=\"".$ip."\" AND time>".(time()-$ip_time)));
		$sofar_ac = mysql_num_rows(mysql_query("SELECT * FROM invitations_sent WHERE accountid=".$user["id"]." AND time>".(time()-$ac_time)));
		$sofar_gr = mysql_num_rows(mysql_query("SELECT * FROM invitations_sent WHERE groupid=\"".$plugin_groupid[$i]."\" AND time>".(time()-$gr_time)));

		if(mysql_num_rows(mysql_query("SELECT * FROM invitations_sent")) > 10000)
			mysql_query("DELETE FROM invitations_sent WHERE time < ".(time()-60*60*3));

		$left_ip = $ip_invites - $sofar_ip;
		$left_ac = $account_invites - $sofar_ac;
		$left_gr = $group_invites - $sofar_gr;

		if($sofar_ip < $sofar_ac)
			if($sofar_ac < $sofar_gr)
				$invites_sofar=$sofar_gr;
			else
				$invites_sofar=$sofar_ac;
		else
			if($sofar_ip < $sofar_gr)
				$invites_sofar = $sofar_gr;
			else
				$invites_sofar = $sofar_ip;

		if($left_ip < $left_ac)
			if($left_ip < $left_gr)
				$left=$left_ip;
			else
				$left=$left_gr;
		else
			if($left_ac < $left_gr)
				$left = $left_ac;
			else
				$left = $left_gr;

		if($left>0)
		{
			if(isset($_GET["communityid"]))
			{
				$result = invite_player($steam_communityid, $_GET["communityid"], $plugin_groupid[$i], $steam_cookie, $sessionid, $ip, $user_agent);
				//file_put_contents("invite.log", $_GET["serverip"]." - ".$_GET["communityid"]." - ".$result."\n", FILE_APPEND);
				if(preg_match("/\<\!\[CDATA\[OK\]\]\>/", $result))
				{
					$invites_now++;
					mysql_query("INSERT INTO invitations_sent (communityid, accountid, groupid, serverip, time) VALUES(\"".$_GET["communityid"]."\", ".$user["id"].", \"".$plugin_groupid[$i]."\", \"".$ip."\", ".time().")");
					/*mysql_query("UPDATE groups SET invitations=".($invites_sofar+1).", lasttime=".$time_of_last." WHERE groupid=\"".$plugin_groupid[$i]."\"");
					mysql_query("UPDATE accounts SET invitations=".($user["invitations"]+1).", last=".$user["last"]." WHERE id=".$user["id"]);
					mysql_query("UPDATE locations SET invitations=".($user["invitations"]+1).", last=".$user["last"]." WHERE id=".$user["id"]);*/
				}
				else if(preg_match("/permission/", $result))
				{
					join_group($plugin_groupid[$i], $steam_cookie, $sessionid, $ip, $user_agent);
					$result = invite_player($steam_communityid, $_GET["communityid"], $plugin_groupid[$i], $steam_cookie, $sessionid, $ip, $user_agent);
					if(preg_match("/\<\!\[CDATA\[OK\]\]\>/", $result))
					{
						$invites_now++;
						mysql_query("INSERT INTO invitations_sent (communityid, accountid, groupid, serverip, time) VALUES(\"".$_GET["communityid"]."\", ".$user["id"].", \"".$plugin_groupid[$i]."\", \"".$ip."\", ".time().")");
					}
				} else if(preg_match("/invalid/", $result))
				{
					// Steam logon expired
					file_put_contents("autoinvite.txt", "");
					die();
				}

				if($_GET["debug"])
				{
						echo $result;
				}
			}

			if($left-$invites_now > 0 && !$lock)
			{
				//Fetch queued players and invite them

				$successful_invites = array();
				$number = $left-$invites_now;

				$mysql_query = mysql_query("SELECT * FROM queued_invitations WHERE groupid=\"".$plugin_groupid[$i]."\" LIMIT 0, ".$number);

				if(mysql_num_rows($mysql_query)>0)
				{
					mysql_query("UPDATE groups SET locked=1 WHERE groupid=\"".$plugin_groupid[$i]."\"");

					while($player = mysql_fetch_assoc($mysql_query))
					{
						//foreach($plugin_groupid AS $comid)
						//{
						$result = invite_player($steam_communityid, $player["communityid"], $plugin_groupid[$i], $steam_cookie, $sessionid, $ip, $user_agent);
						//$log .= $result;

						if(preg_match("/\<\!\[CDATA\[OK\]\]\>/", $result))
						{
							 mysql_query("INSERT INTO invitations_sent (communityid, accountid, groupid, serverip, time) VALUES(\"".$player["communityid"]."\", ".$user["id"].", \"".$plugin_groupid[$i]."\", \"".$ip."\", ".time().")");
						}
						else if(preg_match("/permission/", $result))
						{
							join_group($plugin_groupid[$i], $steam_cookie, $sessionid, $ip, $user_agent);
							$result = invite_player($steam_communityid, $player["communityid"], $plugin_groupid[$i], $steam_cookie, $sessionid, $ip, $user_agent);
							if(preg_match("/\<\!\[CDATA\[OK\]\]\>/", $result))
							{
								$invites_now++;
								mysql_query("INSERT INTO invitations_sent (communityid, accountid, groupid, serverip, time) VALUES(\"".$player["communityid"]."\", ".$user["id"].", \"".$plugin_groupid[$i]."\", \"".$ip."\", ".time().")");
							}
						}
						//}
						$successful_invites[] = $player["communityid"];
					}

					//Delete successfully invited players from database

					$mysql_query_string = "DELETE FROM queued_invitations WHERE groupid=\"".$plugin_groupid[$i]."\" AND (";

					for($a=0;$a<count($successful_invites);++$a)
					{
						$mysql_query_string .= " communityid=\"".$successful_invites[$a]."\"";
						if(($a+1) != count($successful_invites))
							$mysql_query_string .= " OR";
					}

					$mysql_query_string .=")";

					mysql_query($mysql_query_string);

					//file_put_contents("log.txt", $log);

					$invites_sofar += $invites_now;

					mysql_query("UPDATE groups SET locked=0 WHERE groupid=\"".$plugin_groupid[$i]."\"");
					//mysql_query("UPDATE accounts SET invitations=".($user["invitations"]+$invites_now)." WHERE id=".$user["id"]);
				}
			}
		}
		else
		{
			if(isset($_GET["communityid"]) && is_numeric($_GET["communityid"]))
			{
				//Can't invite more today, queueing
				if(mysql_num_rows(mysql_query("SELECT * FROM queued_invitations WHERE communityid=\"".mysql_real_escape_string($_GET["communityid"])."\" AND groupid=\"".$plugin_groupid[$i]."\""))==0 && strlen($_GET["communityid"])==17)
				{
					if(mysql_num_rows(mysql_query("SELECT * FROM queued_invitations WHERE groupid=\"".$groupid."\" AND communityid=\"".$_GET["communityid"]."\"")) == 0)
						mysql_query("INSERT INTO queued_invitations (communityid, groupid) VALUES(\"".mysql_real_escape_string($_GET["communityid"])."\", \"".$plugin_groupid[$i]."\")");
				}
			}
		}
	}

	//Close database connection
	mysql_close();

	//file_put_contents("autoinvite.txt", $invites_sofar."\n".$steam_cookie."\n".$time_of_first);

	function invite_player($inviter, $invitee, $group, $steam_cookie, $sessionid, $ip, $user_agent)
	{
		$data = array("xml"=>"1",
					"type"=>"groupInvite",
					"sessionID"=>urldecode($sessionid),
					"group"=>$group,
					"inviter"=>$inviter,
					"invitee"=>$invitee);
		
		print_r($data);
					
		$steam_invite = curl_init();
		curl_setopt($steam_invite, CURLOPT_URL, "http://steamcommunity.com/actions/GroupInvite");
		curl_setopt($steam_invite, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($steam_invite, CURLOPT_HEADER, 1);
		curl_setopt($steam_invite, CURLOPT_INTERFACE, $ip);
		curl_setopt($steam_invite, CURLOPT_POST, 1);
		curl_setopt($steam_invite, CURLOPT_POSTFIELDS, $data);
		curl_setopt($steam_invite, CURLOPT_COOKIE, "steamLogin=".$steam_cookie."; sessionid=".$sessionid);
		curl_setopt($steam_invite, CURLOPT_USERAGENT, $user_agent);
		$result = curl_exec($steam_invite);
		curl_close($steam_invite);
	
		return $result;
	}

	function join_group($groupid, $steam_cookie, $sessionid, $ip, $user_agent)
	{
		$steam_group = curl_init();
		curl_setopt($steam_group, CURLOPT_URL, "http://steamcommunity.com/gid/".$groupid);
		curl_setopt($steam_group, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($steam_group, CURLOPT_HEADER, 1);
		curl_setopt($steam_group, CURLOPT_COOKIE, "steamLogin=".$steam_cookie."; sessionid=".$sessionid);
		curl_setopt($steam_group, CURLOPT_USERAGENT, $user_agent);
		$result = curl_exec($steam_group);
		curl_close($steam_group);
		if(preg_match("/Location: http:\/\/steamcommunity.com\/groups\/(.*)\r\n/", $result, $group))
			$group_name = $group[1];

		if($_GET["debug"])
			echo $result;

		$data = array("action"=>"login",
						"sessionID"=>$sessionid);
		$group_join = curl_init();
		curl_setopt($group_join, CURLOPT_URL, "http://steamcommunity.com/groups/".$group_name);
		curl_setopt($group_join, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($group_join, CURLOPT_HEADER, 1);
		curl_setopt($group_join, CURLOPT_POST, 1);
		curl_setopt($group_join, CURLOPT_POSTFIELDS, $data);
		curl_setopt($group_join, CURLOPT_INTERFACE, $ip);
		curl_setopt($group_join, CURLOPT_USERAGENT, $user_agent);
		curl_setopt($group_join, CURLOPT_COOKIE, "steamLogin=".$steam_cookie."; sessionid=".$sessionid);
		$result = curl_exec($group_join);
		curl_close($group_join);

		if($_GET["debug"])
			echo $result;
	}
?>
