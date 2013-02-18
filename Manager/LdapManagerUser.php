<?php

namespace IMAG\LdapBundle\Manager;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

class LdapManagerUser implements LdapManagerUserInterface
{
    private
        $ldapConnection,
        $username,
        $password,
        $params = array(),
        $_ldapUser = null
        ;

    public function __construct(LdapConnectionInterface $conn)
    {
        $this->ldapConnection = $conn;
        $this->params = $this->ldapConnection
            ->getParameters();
    }

    public function exists($username)
    {
        return (bool) $this
            ->setUsername($username)
            ->addLdapUser()
            ;
    }

    public function auth()
    {
        return (bool) ($this->doPass() && $this->bind());
    }

    public function authNoAnonSearch()
    {
        return (bool) ($this->bindUser() && $this->doPass());
    }

    public function doPass()
    {
        try {
            $this->addLdapUser();
            $this->addLdapRoles();

        } catch(\InvalidArgumentException $e) {
            if (false === $this->params['client']['skip_roles']) {
                throw $e;
            }

        }
        
        return $this;
    }

    public function getDn()
    {
        return $this->_ldapUser['dn'];
    }

    public function getEmail()
    {
        return isset($this->_ldapUser['mail'][0]) ? $this->_ldapUser['mail'][0] : '';
    }

    public function getAttributes()
    {
        $attributes = array();
        foreach ($this->params['user']['attributes'] as $attrName) {
            if (isset($this->_ldapUser[$attrName][0])) {
                $attributes[$attrName] = $this->_ldapUser[$attrName][0];
            }
        }
        return $attributes;
    }

    public function getLdapUser()
    {
        return $this->_ldapUser;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getRoles()
    {
        return $this->_ldapUser['roles'];
    }

    public function setUsername($username)
    {
        if ($username === "*") {
            throw new \InvalidArgumentException("Invalid username given.");
        }

        $this->username = $username;

        return $this;
    }

    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    private function addLdapUser()
    {
        if (!$this->username) {
            throw new \Exception('User is not defined, pls use setUsername');
        }

        $filter = isset($this->params['user']['filter'])
            ? $this->params['user']['filter']
            : '';

        $entries = $this->ldapConnection
            ->search(array(
                'base_dn' => $this->params['user']['base_dn'],
                'filter' => sprintf('(&%s(%s=%s))',
                                    $filter,
                                    $this->params['user']['name_attribute'],
                                    $this->ldapConnection->escape($this->username)
                )
            ));

        if ($entries['count'] > 1) {
            throw new \Exception("This search can only return a single user");
        }

        if ($entries['count'] == 0) {
            return false;
        }

        $this->_ldapUser = $entries[0];

        return $this;
    }

    private function addLdapRoles()
    {
        if (null === $this->_ldapUser) {
            throw new \RuntimeException('AddRoles() can be involved only when addUser() have return an user');
        }
        
        $this->_ldapUser['roles'] = array();

        if (!isset($this->params['role'])) {
            throw new \InvalidArgumentException("If you want skip the roles getting, please set skip_roles to true under client key");
        }

        $tab = $this->getGroups($this->getUserId());

        $this->_ldapUser['roles'] = $tab;

        return $this;
    }

    private function bind()
    {
        return $this->ldapConnection
            ->bind($this->_ldapUser['dn'], $this->password);
    }

    private function bindUser()
    {
        $username = $this->username;
        if (isset($this->params['client']['bind_user_pattern'])) {
            $username = str_replace('&username&', $username,$this->params['client']['bind_user_pattern']);
        }
        return $this->ldapConnection
            ->bind($username, $this->password);
    }

    private function getGroups($memberDn, $depth = 0)
    {
        if ($depth >= 10) {
            throw new \Exception('LDAP Group recursion is too deep');
        }

        $tab = array();

        $filter = isset($this->params['role']['filter'])
            ? $this->params['role']['filter']
            : '';

        $entries = $this->ldapConnection
            ->search(array(
                'base_dn'  => $this->params['role']['base_dn'],
                'filter'   => sprintf('(&%s(%s=%s))',
                                      $filter,
                                      $this->params['role']['user_attribute'],
                                      $this->ldapConnection->escape($memberDn)
                ),
                'attrs'    => array(
                    $this->params['role']['name_attribute'],
                    $this->params['role']['user_id']
                )
            ));

        for ($i = 0; $i < $entries['count']; $i++) {
            array_push($tab, sprintf('ROLE_%s',
                                     self::slugify($entries[$i][$this->params['role']['name_attribute']][0])
            ));
            if ($this->params['role']['recursive_search']) {
                $tab = array_unique(
                    array_merge(
                        $tab,
                        $this->getGroups($entries[$i][$this->params['role']['user_id']], $depth + 1)
                    )
                );
            }
        }

        return $tab;
    }

    private static function slugify($role)
    {
        $role = preg_replace('/\W+/', '_', $role);
        $role = trim($role, '_');
        $role = strtoupper($role);

        return $role;
    }

    private function getUserId()
    {
        switch ($this->params['role']['user_id']) {
        case 'dn':
            return $this->_ldapUser['dn'];
            break;

        case 'username':
            return $this->username;
            break;

        default:
            throw new \Exception(sprintf('The value can\'t be retrieve for this user_id : %s',$this->params['role']['user_id']));
        }
    }
}
