<?php
/**
 * Testes para SISTUR_Permissions
 *
 * @package SISTUR
 */

class Test_SISTUR_Permissions extends WP_UnitTestCase {

    /**
     * Setup antes de cada teste
     */
    public function setUp(): void {
        parent::setUp();
        $this->permissions = SISTUR_Permissions::get_instance();
    }

    /**
     * Teardown após cada teste
     */
    public function tearDown(): void {
        parent::tearDown();
        $this->permissions->clear_cache();
    }

    /**
     * Testa se a instância é singleton
     */
    public function test_singleton_instance() {
        $instance1 = SISTUR_Permissions::get_instance();
        $instance2 = SISTUR_Permissions::get_instance();

        $this->assertSame($instance1, $instance2, 'As instâncias devem ser a mesma (Singleton)');
    }

    /**
     * Testa criação de papel
     */
    public function test_create_role() {
        $role_id = $this->permissions->create_role(
            'Gerente de Teste',
            'Papel para testes unitários',
            false
        );

        $this->assertIsInt($role_id, 'ID do papel deve ser inteiro');
        $this->assertGreaterThan(0, $role_id, 'ID do papel deve ser maior que zero');

        // Verificar se o papel foi criado
        $role = $this->permissions->get_role($role_id);
        $this->assertIsArray($role, 'Papel deve retornar array');
        $this->assertEquals('Gerente de Teste', $role['name'], 'Nome do papel deve corresponder');
        $this->assertEquals(0, $role['is_admin'], 'Papel não deve ser admin');
    }

    /**
     * Testa atualização de papel
     */
    public function test_update_role() {
        // Criar papel
        $role_id = $this->permissions->create_role('Teste Update', 'Descrição original', false);

        // Atualizar
        $result = $this->permissions->update_role($role_id, array(
            'description' => 'Descrição atualizada',
            'is_admin' => 1
        ));

        $this->assertTrue($result, 'Atualização deve retornar true');

        // Verificar atualização
        $role = $this->permissions->get_role($role_id);
        $this->assertEquals('Descrição atualizada', $role['description'], 'Descrição deve estar atualizada');
        $this->assertEquals(1, $role['is_admin'], 'Papel deve ser admin agora');
    }

    /**
     * Testa verificação de permissões
     */
    public function test_can_permission() {
        global $wpdb;

        // Criar funcionário de teste
        $employee_id = $wpdb->insert(
            $wpdb->prefix . 'sistur_employees',
            array(
                'name' => 'Funcionário Teste',
                'email' => 'teste@example.com',
                'status' => 1
            ),
            array('%s', '%s', '%d')
        );

        $employee_id = $wpdb->insert_id;

        // Funcionário sem papel não deve ter permissões
        $has_permission = $this->permissions->can($employee_id, 'employees', 'view');
        $this->assertFalse($has_permission, 'Funcionário sem papel não deve ter permissões');

        // Cleanup
        $wpdb->delete($wpdb->prefix . 'sistur_employees', array('id' => $employee_id));
    }

    /**
     * Testa listagem de todos os papéis
     */
    public function test_get_all_roles() {
        $roles = $this->permissions->get_all_roles();

        $this->assertIsArray($roles, 'Deve retornar um array');
        $this->assertNotEmpty($roles, 'Deve ter pelo menos os papéis padrão');

        // Verificar estrutura
        foreach ($roles as $role) {
            $this->assertArrayHasKey('id', $role);
            $this->assertArrayHasKey('name', $role);
            $this->assertArrayHasKey('description', $role);
            $this->assertArrayHasKey('is_admin', $role);
        }
    }

    /**
     * Testa atribuição de permissões a papel
     */
    public function test_assign_permissions_to_role() {
        // Criar papel de teste
        $role_id = $this->permissions->create_role('Teste Permissões', 'Para testar atribuição', false);

        // Buscar IDs de algumas permissões
        global $wpdb;
        $permission_ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}sistur_permissions LIMIT 3"
        );

        $this->assertNotEmpty($permission_ids, 'Deve ter permissões no banco');

        // Atribuir permissões
        $result = $this->permissions->assign_permissions_to_role($role_id, $permission_ids);
        $this->assertTrue($result, 'Atribuição deve retornar true');

        // Verificar atribuição
        $assigned = $this->permissions->get_role_permissions($role_id);
        $this->assertCount(count($permission_ids), $assigned, 'Deve ter atribuído todas as permissões');
    }
}
