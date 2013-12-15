<?php
    include_once_exists(dirname(__FILE__)."/../../config/config.smf.php");
    
    array_push(PluginRegistry::$Classes, "SMFBinding");
    
    class SMFBinding
    {
        public static $HashMethod = "smf_sha1s";
        
        public $BindingName = "smf";
        private $mConnector = null;
    
        // -------------------------------------------------------------------------
        
        public function isActive()
        {
            return defined("SMF_BINDING") && SMF_BINDING;
        }
        
        // -------------------------------------------------------------------------
        
        public function getConfig()
        {
            return array(
                "database"  => defined("SMF_DATABASE") ? SMF_DATABASE : RP_DATABASE,
                "user"      => defined("SMF_USER") ? SMF_USER : RP_USER,
                "password"  => defined("SMF_PASS") ? SMF_PASS : RP_PASS,
                "prefix"    => defined("SMF_TABLE_PREFIX") ? SMF_TABLE_PREFIX : "smf_",
                "autologin" => defined("SMF_AUTOLOGIN") ? SMF_AUTOLOGIN : false,
                "cookie"    => defined("SMF_COOKIE") ? SMF_COOKIE : "SMFCookie956",
                "members"   => defined("SMF_RAIDLEAD_GROUPS") ? explode(",", SMF_RAIDLEAD_GROUPS ) : [],
                "leads"     => defined("SMF_MEMBER_GROUPS") ? explode(",", SMF_MEMBER_GROUPS ) : [],
                "cookie_ex" => true,
                "groups"    => true
            );
        }
        
        // -------------------------------------------------------------------------
        
        public function queryExternalConfig($aRelativePath)
        {
            $ConfigPath = $_SERVER["DOCUMENT_ROOT"]."/".$aRelativePath."/Settings.php";
            if (!file_exists($ConfigPath))
            {
                Out::getInstance()->pushError($ConfigPath." ".L("NotExisting").".");
                return null;
            }
            
            @include_once($ConfigPath);
            
            if (!isset($mbname))
            {
                Out::getInstance()->pushError(L("NoValidConfig"));
                return null;
            }
            
            return array(
                "database"  => $db_name,
                "user"      => $db_user,
                "password"  => $db_passwd,
                "prefix"    => $db_prefix,
                "cookie"    => (isset($cookiename)) ? $cookiename : "SMFCookie956"
            );
        }
        
        // -------------------------------------------------------------------------
        
        public function isConfigWriteable()
        {
            $ConfigFolder = dirname(__FILE__)."/../../config";
            $ConfigFile   = $ConfigFolder."/config.smf.php";
            
            return (!file_exists($ConfigFile) && is_writable($ConfigFolder)) || is_writable($ConfigFile);
        }
        
        // -------------------------------------------------------------------------
        
        public function writeConfig($aEnable, $aDatabase, $aPrefix, $aUser, $aPass, $aAutoLogin, $aMembers, $aLeads, $aCookieEx)
        {
            $Config = fopen( dirname(__FILE__)."/../../config/config.smf.php", "w+" );
            
            fwrite( $Config, "<?php\n");
            fwrite( $Config, "\tdefine(\"SMF_BINDING\", ".(($aEnable) ? "true" : "false").");\n");
            
            if ( $aEnable )
            {
                fwrite( $Config, "\tdefine(\"SMF_DATABASE\", \"".$aDatabase."\");\n");
                fwrite( $Config, "\tdefine(\"SMF_USER\", \"".$aUser."\");\n");
                fwrite( $Config, "\tdefine(\"SMF_PASS\", \"".$aPass."\");\n");
                fwrite( $Config, "\tdefine(\"SMF_TABLE_PREFIX\", \"".$aPrefix."\");\n");
                fwrite( $Config, "\tdefine(\"SMF_COOKIE\", \"".$aCookieEx."\");\n");
                fwrite( $Config, "\tdefine(\"SMF_AUTOLOGIN\", ".(($aAutoLogin) ? "true" : "false").");\n");
                                             
                fwrite( $Config, "\tdefine(\"SMF_MEMBER_GROUPS\", \"".implode( ",", $aMembers )."\");\n");
                fwrite( $Config, "\tdefine(\"SMF_RAIDLEAD_GROUPS\", \"".implode( ",", $aLeads )."\");\n");
            }
            
            fwrite( $Config, "?>");    
            fclose( $Config );
        }
        
        // -------------------------------------------------------------------------
        
        public function getGroups($aDatabase, $aPrefix, $aUser, $aPass, $aThrow)
        {
            $Connector = new Connector(SQL_HOST, $aDatabase, $aUser, $aPass, $aThrow);
            
            if ($Connector != null)
            {
                $GroupQuery = $Connector->prepare( "SELECT id_group, group_name FROM `".$aPrefix."membergroups` ORDER BY group_name" );
                $Groups = [];
                
                if ( $GroupQuery->execute() )
                {
                    while ( $Group = $GroupQuery->fetch(PDO::FETCH_ASSOC) )
                    {
                        array_push( $Groups, array(
                            "id"   => $Group["id_group"], 
                            "name" => $Group["group_name"])
                        );
                    }
                }
                else if ($aThrow)
                {
                    $Connector->throwError($GroupQuery);
                }
                
                $GroupQuery->closeCursor();
                return $Groups;
            }
            
            return null;
        }
        
        // -------------------------------------------------------------------------
        
        public function getGroupsFromConfig()
        {
            $Config = $this->getConfig();
            return $this->getGroups($Config["database"], $Config["prefix"], $Config["user"], $Config["password"], false);
        }
        
        // -------------------------------------------------------------------------
        
        private function getGroup( $aUserData )
        {
            if ($aUserData["ban_time"] > 0)
            {
                $CurrentTime = time();
                if ( ($aUserData["ban_time"] < $CurrentTime) &&
                     (($aUserData["expire_time"] == 0) || ($aUserData["expire_time"] > $CurrentTime)) )
                {
                    return "none"; // ### return, banned ###
                }
            };
            
            $MemberGroups   = explode(",", SMF_MEMBER_GROUPS );
            $RaidleadGroups = explode(",", SMF_RAIDLEAD_GROUPS );
            $DefaultGroup   = "none";
            
            $Groups = explode(",", $aUserData["additional_groups"]);
            array_push($Groups, $aUserData["id_group"] );
            
            foreach( $Groups as $Group )
            {
                if ( in_array($Group, $MemberGroups) )
                    $DefaultGroup = "member";
                   
                if ( in_array($Group, $RaidleadGroups) )
                    return "raidlead"; // ### return, best possible group ###
            }
            
            return $DefaultGroup;
        }
        
        // -------------------------------------------------------------------------
        
        private function generateInfo( $aUserData )
        {
            $Info = new UserInfo();
            $Info->UserId      = $aUserData["id_member"];
            $Info->UserName    = $aUserData["member_name"];
            $Info->Password    = $aUserData["passwd"];
            $Info->Salt        = strtolower($aUserData["member_name"]);
            $Info->SessionSalt = $aUserData["password_salt"];
            $Info->Group       = $this->getGroup($aUserData);
            $Info->BindingName = $this->BindingName;
            $Info->PassBinding = $this->BindingName;
        
            return $Info;
        }
        
        // -------------------------------------------------------------------------
        
        public function getExternalLoginData()
        {
            if (!defined("SMF_AUTOLOGIN") || !SMF_AUTOLOGIN)
                return null;
                
            $UserInfo = null;
            
            // Fetch user info if seesion cookie is set
            
            if (defined("SMF_COOKIE") && isset($_COOKIE[SMF_COOKIE]))
            {
                $CookieData = unserialize($_COOKIE[SMF_COOKIE]);
                $UserId  = $CookieData[0];
                $PwdHash = $CookieData[1];
                
                $UserInfo = $this->getUserInfoById($UserId);
                
                $SessionHash = sha1($UserInfo->Password.$UserInfo->SessionSalt);
                if ($PwdHash != $SessionHash)
                    $UserInfo = null;
            }
            
            return $UserInfo;
        }
        
        // -------------------------------------------------------------------------
        
        public function getUserInfoByName( $aUserName )
        {
            if ($this->mConnector == null)
                $this->mConnector = new Connector(SQL_HOST, SMF_DATABASE, SMF_USER, SMF_PASS);
            
            $UserSt = $this->mConnector->prepare("SELECT id_member, member_name, passwd, password_salt, id_group, additional_groups, ban_time, expire_time ".
                                                "FROM `".SMF_TABLE_PREFIX."members` ".
                                                "LEFT JOIN `".SMF_TABLE_PREFIX."ban_items` USING(id_member) ".
                                                "LEFT JOIN `".SMF_TABLE_PREFIX."ban_groups` USING(id_ban_group) ".
                                                "WHERE LOWER(member_name) = :Login LIMIT 1");
                                          
            $UserSt->BindValue( ":Login", strtolower($aUserName), PDO::PARAM_STR );
        
            if ( $UserSt->execute() && ($UserSt->rowCount() > 0) )
            {
                $UserData = $UserSt->fetch( PDO::FETCH_ASSOC );
                $UserSt->closeCursor();
                
                return $this->generateInfo($UserData);
            }
        
            $UserSt->closeCursor();
            return null;
        }
        
        // -------------------------------------------------------------------------
        
        public function getUserInfoById( $aUserId )
        {
            if ($this->mConnector == null)
                $this->mConnector = new Connector(SQL_HOST, SMF_DATABASE, SMF_USER, SMF_PASS);
            
            $UserSt = $this->mConnector->prepare("SELECT id_member, member_name, passwd, password_salt, id_group, additional_groups, ban_time, expire_time ".
                                                "FROM `".SMF_TABLE_PREFIX."members` ".
                                                "LEFT JOIN `".SMF_TABLE_PREFIX."ban_items` USING(id_member) ".
                                                "LEFT JOIN `".SMF_TABLE_PREFIX."ban_groups` USING(id_ban_group) ".
                                                "WHERE id_member = :UserId LIMIT 1");
                                          
            $UserSt->BindValue( ":UserId", $aUserId, PDO::PARAM_INT );
        
            if ( $UserSt->execute() && ($UserSt->rowCount() > 0) )
            {
                $UserData = $UserSt->fetch( PDO::FETCH_ASSOC );
                $UserSt->closeCursor();
                
                return $this->generateInfo($UserData);
            }
        
            $UserSt->closeCursor();
            return null;
        }
        
        // -------------------------------------------------------------------------
        
        public function getMethodFromPass( $aPassword )
        {
            return self::$HashMethod;
        }
        
        // -------------------------------------------------------------------------
        
        public static function hash( $aPassword, $aSalt, $aMethod )
        {
            return sha1($aSalt.$aPassword);
        }
    }
?>