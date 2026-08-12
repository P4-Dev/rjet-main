<?php

declare(strict_types=1);

return [
    'label' => 'Solicitação de pagamento',
    'plural' => 'Solicitações de pagamento',

    'fields' => [
        'company' => 'Empresa',
        'branch_id' => 'Filial',
        'supplier_id' => 'Fornecedor',
        'cost_center_id' => 'Centro de custo',
        'appropriation_id' => 'Apropriação',
        'payment_method' => 'Forma de pagamento',
        'gross_amount' => 'Valor bruto',
        'discount_amount' => 'Descontos/deduções',
        'net_amount' => 'Valor líquido',
        'due_date' => 'Vencimento',
        'has_attachments' => 'Anexos',
        'attachments' => 'Anexos',
        'next_status' => 'Novo status',
        'approval_state' => 'Aprovação',
        'approver' => 'Aprovador',
        'due_at' => 'Prazo SLA',
        'escalated_at' => 'Escalada em',
        'has_approved_for_launch' => 'Pronta para lançar',
    ],

    'sections' => [
        'identification' => 'Identificação',
        'amounts' => 'Valores',
        'payment' => 'Pagamento e comprovantes',
        'settlement' => 'Dados de liquidação',
        'approval' => 'Aprovação',
    ],

    'hints' => [
        'select_branch_first' => 'Selecione a filial para carregar as opções.',
        'appropriation_required' => 'Esta empresa exige apropriação na solicitação.',
        'appropriation_optional' => 'Opcional para esta empresa; pode ser preenchida depois.',
        'net_amount_auto' => 'Calculado automaticamente: valor bruto menos descontos.',
        'digitable_line' => 'Preenchida automaticamente quando um boleto em PDF é anexado.',
        'pix_qr_code' => 'Cole aqui o código Pix copia-e-cola (payload do QR Code).',
        'holder_document' => 'CPF ou CNPJ do favorecido (somente números).',
        'settlement' => 'Os campos exibidos variam conforme a forma de pagamento escolhida.',
        'attachments' => 'PDF, JPEG, PNG ou WEBP. Máximo de 10 MB por arquivo. Obrigatório para boleto.',
    ],

    'filters' => [
        'due_from' => 'Vencimento de',
        'due_until' => 'Vencimento até',
        'created_from' => 'Criada de',
        'created_until' => 'Criada até',
        'approval_status' => 'Status da aprovação',
        'awaiting_my_approval' => 'Pendentes para mim',
    ],

    'tabs' => [
        'all' => 'Todas',
        'awaiting_my_approval' => 'Minhas pendências',
        'all_pending_approvals' => 'Aguardando aprovação',
        'returned' => 'Devolvidas',
    ],

    'approval_states' => [
        'awaiting' => 'Aguardando aprovação',
        'returned' => 'Devolvida',
        'approved_ready' => 'Aprovada (pronta para lançar)',
        'no_rule' => 'Sem regra de alçada',
        'none' => '—',
    ],

    'actions' => [
        'transition_status' => 'Alterar status',
        'extract_ocr' => 'Ler boleto (OCR)',
        'approve' => 'Aprovar',
        'reject' => 'Rejeitar',
        'resubmit' => 'Reenviar para aprovação',
        'send_for_approval' => 'Enviar para aprovação',
    ],

    'messages' => [
        'confirm_transition' => 'Confirme o novo status desta solicitação.',
        'status_changed' => 'Status atualizado com sucesso!',
        'ocr_succeeded' => 'Dados do boleto extraídos com sucesso. Revise antes de salvar.',
        'ocr_failed' => 'Não foi possível ler o boleto automaticamente. Preencha os dados manualmente.',
        'ocr_image_not_supported' => 'Leitura automática disponível apenas para boletos em PDF. Preencha os dados manualmente.',
        'ocr_disabled' => 'Leitura automática de boleto está desabilitada.',
        'ocr_not_found' => 'Nenhuma linha digitável válida foi encontrada no PDF.',
        'no_attachments' => 'Nenhum anexo.',
        'confirm_approve' => 'Confirma a aprovação desta solicitação?',
        'approved' => 'Solicitação aprovada.',
        'rejected' => 'Solicitação rejeitada e devolvida ao solicitante.',
        'resubmitted' => 'Solicitação reenviada para aprovação.',
        'sent_for_approval' => 'Solicitação enviada para aprovação.',
        'routed_no_rule' => 'Solicitação criada, mas nenhuma regra de alçada corresponde. Configure uma faixa ou use Enviar para aprovação.',
    ],

    'errors' => [
        'branch_not_allowed' => 'Você não tem permissão para criar solicitações nesta filial.',
        'invalid_status_transition' => 'Esta transição de status não é permitida.',
        'unauthorized_status_transition' => 'Você não tem permissão para alterar o status desta solicitação.',
        'cannot_edit_in_status' => 'Você não pode editar uma solicitação neste status.',
        'appropriation_required' => 'A apropriação é obrigatória para esta empresa.',
        'appropriation_not_allowed' => 'A apropriação informada não pertence à empresa da filial.',
        'cost_center_not_allowed' => 'O centro de custo informado não pertence à filial selecionada.',
        'boleto_attachment_required' => 'Anexe pelo menos um arquivo do boleto.',
        'discount_exceeds_gross' => 'O desconto não pode ser maior que o valor bruto.',
        'invalid_net_amount' => 'Os valores informados são inválidos.',
        'incomplete_bank_details' => 'Os dados de liquidação estão incompletos.',
        'pix_details_incomplete' => 'Informe a chave Pix (tipo + chave) ou o código do QR Code.',
        'transfer_details_incomplete' => 'Preencha todos os dados obrigatórios da transferência.',
        'invalid_holder_document' => 'O CPF/CNPJ do favorecido é inválido.',
        'approval_required' => 'É necessário uma aprovação válida (sem alterações materiais) antes de lançar.',
    ],
];
