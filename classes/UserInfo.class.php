<?php
/**
 * Class to handle user account info for the Classifieds plugin.
 *
 * @author      Lee Garner <lee@leegarner.com>
 * @copyright   Copyright (c) 2009-2020 Lee Garner <lee@leegarner.com>
 * @package     classifieds
 * @version     v1.3.0
 * @license     http://opensource.org/licenses/gpl-2.0.php
 *              GNU Public License v2 or later
 * @filesource
 */
namespace Classifieds;


/**
 * Class for user account info.
 * @package classifieds
 */
class UserInfo
{
    /** glFusion user ID.
     * @var integer */
    private $uid = 0;

    /** Street address.
     * @var string */
    private $address = '';

    /** City name.
     * @var string */
    private $city = '';

    /** State.
     * @var string */
    private $state = '';

    /** Zip code.
     * @var string */
    private $postcode = '';

    /** Telephone number.
     * @var string */
    private $tel = '';

    /** Notify prior to expiration?
     * @var integer */
    private $notify_exp = 0;

    /** Notify when a comment is left?
     * @var integer */
    private $notify_comment = 0;

    /** Balance available to run ads, in days.
     * @var integer */
    private $days_balance = 0;

    /** Field names and types.
     * @var array */
    private $fields = array(
        'uid' => 'int',
        'address' => 'string',
        'city' => 'string',
        'state' => 'string',
        'postcode' => 'string',
        'tel' => 'string',
        'notify_exp' => 'bool',
        'notify_comment' => 'bool',
        'days_balance' => 'int',
    );

    /** Max days that user can run an ad.
     * @var integer */
    private $max_ad_days;


    /**
     * Constructor.
     * Reads in the specified class, if $id is set.  If $id is zero,
     * then a new entry is being created.
     *
     * @param   integer $uid    Optional User ID
     */
    public function __construct($uid=0)
    {
        global $_USER;

        $this->properties = array();
        $uid = (int)$uid;
        if ($uid < 1) {
            $uid = (int)$_USER['uid'];
        }
        $this->uid = $uid;
        if (!$this->ReadOne()) {
            $this->notify_exp = 1;
            $this->notify_comment = 1;
        }
    }


    /**
     * Returns the maximum number of days this user can add to an ad.
     *
     * @return  integer     Max days
     */
    public function getMaxDays()
    {
        return $this->max_ad_days;
    }


    /**
     * Sets all variables to the matching values from $rows.
     *
     * @param   array   $A  Array of values, from DB or $_POST
     */
    public function SetVars($A)
    {
        if (!is_array($A)) return;

        if (isset($A['advt_address'])) {
            // $_POST from a form, fields are prefixed
            $pfx = 'advt_';
        } else {
            // From the database, no prefix
            $pfx = '';
        }

        foreach ($this->fields as $fld=>$type) {
            switch ($type) {
            case 'int':
                $this->$fld = (int)$A[$pfx.$fld];
                break;
            case 'bool':
                $this->$fld = isset($A[$pfx.$fld]) && $A[$pfx.$fld] ? 1 : 0;
                break;
            default:
                $this->$fld = isset($A[$pfx.$fld]) ? $A[$pfx.$fld] : '';
            }
        }

        // Update the actual max number of days that this user can
        // run an ad
        $this->setMaxDays();
    }


    /**
     * Read one user from the database.
     *
     * @param   integer $uid    Optional User ID.  Current ID is used if zero.
     * @return  boolean     True if record found, False if not
     */
    public function ReadOne($uid = 0)
    {
        global $_TABLES;

        $uid = (int)$uid;
        if ($uid == 0) $uid = $this->uid;
        if ($uid == 0) {
            return false;
        }

        $cache_key = 'uid_' . $uid;
        $row = Cache::get($cache_key);
        if ($row === NULL) {
            $result = DB_query("SELECT * from {$_TABLES['ad_uinfo']}
                                WHERE uid=$uid");
            if ($result && DB_numRows($result) == 1) {
                $row = DB_fetchArray($result, false);
            }
        }
        if (is_array($row)) {
            Cache::set($cache_key, $row, 'users');
            $this->SetVars($row);
            return true;
        } else {
            return false;
        }
    }


    /**
     * Save the current values to the database.
     */
    public function Save()
    {
        global $_TABLES, $LANG_ADVT;

        $sql = "INSERT INTO {$_TABLES['ad_uinfo']}
                (uid, address, city, state, postcode,
                tel, notify_exp, notify_comment)
            VALUES (
                '{$this->uid}',
                '" . DB_escapeString($this->address) . "',
                '" . DB_escapeString($this->city) . "',
                '" . DB_escapeString($this->state) . "',
                '" . DB_escapeString($this->postcode) . "',
                '" . DB_escapeString($this->tel) . "',
                '{$this->notify_exp}',
                '{$this->notify_comment}'
            )
            ON DUPLICATE KEY UPDATE
                address = '" . DB_escapeString($this->address) . "',
                city = '" . DB_escapeString($this->city) . "',
                state = '" . DB_escapeString($this->state) . "',
                postcode = '" . DB_escapeString($this->postcode) . "',
                tel = '" . DB_escapeString($this->tel) . "',
                notify_exp = '{$this->notify_exp}',
                notify_comment = '{$this->notify_comment}'
            ";
        //echo $sql;die;
        DB_query($sql);
        if (!DB_error()) {
            COM_setMsg($LANG_ADVT['msg_account_updated']);
            Cache::delete('uid_' . $this->uid);
        } else {
            COM_setMsg($LANG_ADVT['msg_save_error'], 'error');
        }
    }


    /**
     * Delete a specific user's info from the database.
     *
     * @param   integer $uid    ID of user being deleted
     */
    public static function Delete($uid)
    {
        global $_TABLES;

        $uid = (int)$uid;
        DB_delete($_TABLES['ad_uinfo'], 'uid', $uid);
        Cache::delete('uid_' . $uid);
    }


    /**
     * Creates the edit form.
     *
     * @param   string  $type   Type of form, plugin or glFusion acct settings
     * @return  string          HTML for edit form
     */
    public function showForm($type = 'advt')
    {
        global $_TABLES, $_CONF, $_CONF_ADVT, $LANG_ADVT, $_USER;

        $T = new \Template($_CONF_ADVT['path'] . '/templates');
        $T->set_file('accountinfo', "account_settings.thtml");
        if ($type != 'advt') {
            // Called from the use settings page
            $T->set_var('profileedit', 'true');
        }

        $T->set_var(array(
            'uid'               => $this->uid,
            'uinfo_address'     => $this->address,
            'uinfo_city'        => $this->city,
            'uinfo_state'       => $this->state,
            'uinfo_tel'         => $this->tel,
            'uinfo_postcode'    => $this->postcode,
            'exp_notify_checked' => $this->notify_exp == 1 ?
                        'checked="checked"' : '',
            'cmt_notify_checked' => $this->notify_comment == 1 ?
                        'checked="checked"' : '',
        ) );

        $T->parse('output','accountinfo');
        return $T->finish($T->get_var('output'));
    }   // function showForm()


    /**
     * Update the max days balance by adding a given value.
     * Value may be positive or negative.
     *
     * @param   integer $value   Value to add to the current balance
     * @param   integer $uid     User ID to modify, empty to use the current one.
     */
    public function UpdateDaysBalance($value, $uid=0)
    {
        global $_TABLES;

        $uid = (int)$uid;
        if ($uid == 0) {
            if (is_object($this))
                $uid = $this->uid;
            else
                return;
        }

        // Calculate the new balance, which cannot fall below zero.
        $this->days_balance = min($this->days_balance + (int)$value, 0);
        /*$value = (int)$value;
        $newvalue = $this->days_balance + $value;
        if ($newvalue < 0) $newvalue = 0;
        $this->days_balance = $newvalue;
        */

        $sql = "UPDATE {$_TABLES['ad_uinfo']}
            SET days_balance = {$this->days_balance}
            WHERE uid = $uid";
        //echo $sql;die;
        DB_query($sql);
        Cache::delete('uid_' . $uid);
    }


    /**
     * Sets the local variable for the maximum number of days for an ad.
     * This is used if ad purchasing or earning is enabled.
     */
    public function setMaxDays()
    {
        global $_TABLES, $_CONF_ADVT, $_USER, $_GROUPS;

        if (is_null($this->days_balance) ||
                $this->uid != $_USER['uid'] ||
                !$_CONF_ADVT['purchase_enabled']) {
            $this->max_ad_days = (int)$_CONF_ADVT['max_total_duration'];
            return;
        }

        // Current user is excluded from restrictions, use global amount
        foreach ($_CONF_ADVT['purchase_exclude_groups'] as $ex_grp) {
            if (array_key_exists($ex_grp, $_GROUPS)) {
                $this->max_ad_days = (int)$_CONF_ADVT['max_total_duration'];
                return;
            }
        }

        // Otherwise, use the current user's balance
        $this->max_ad_days = $this->days_balance;
    }


    /**
     * Perform privacy export of user fields.
     * Only exports fields containing data, and does not provide the
     * top-level wrapper tag.
     *
     * @return  string      XML-formatted user data
     */
    public function Export()
    {
        $retval = '';
        foreach ($this->fields as $name=>$type) {
            // Don't need to export these:
            if ($name == 'uid' || $name == 'days_bal') continue;
            $d = addSlashes(htmlentities($this->$name));
            if (!empty($d)) {
                $retval .= '<'.$name.'>'.$d.'</'.$name.">\n";
            }
        }
        return $retval;
    }


    /**
     * Get the telephone number.
     *
     * @return  string      Phone number
     */
    public function getTelephone()
    {
        return $this->tel;
    }


    /**
     * Get the street address.
     *
     * @return  string      Street address
     */
    public function getAddress()
    {
        return $this->address;
    }


    /**
     * Get the City name.
     *
     * @return  string      City name
     */
    public function getCity()
    {
        return $this->city;
    }


    /**
     * Get the State name.
     *
     * @return  string      State
     */
    public function getState()
    {
        return $this->state;
    }


    /**
     * Get the postal code.
     *
     * @return  string      Postal code
     */
    public function getPostal()
    {
        return $this->postcode;
    }


}   // class UserInfo

?>
