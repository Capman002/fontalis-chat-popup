document.addEventListener("DOMContentLoaded", function () {
  // Encontra os elementos do chat com IDs únicos Fontalis
  const chatMessages = document.getElementById("fontalis-chatMessages");
  const userInput = document.getElementById("fontalis-userInput");
  const sendButton = document.getElementById("fontalis-sendBtn");
  const sidebar = document.getElementById("fontalis-sidebar");
  const sidebarToggle = document.getElementById("fontalis-sidebar-toggle");
  const sidebarToggleInner = document.getElementById(
    "fontalis-sidebar-toggle-inner"
  );
  const newChatBtn = document.getElementById("fontalis-newChatBtn");
  const searchInput = document.getElementById("fontalis-history-search");
  const chatContainer = document.querySelector(".fontalis-chat-container");

  // Verifica se os elementos essenciais do chat estão na página
  if (!chatMessages || !userInput || !sendButton) {
    // console.warn("Elementos do chat não encontrados. O script não será executado.");
    return;
  }

  // Pega os dados do WordPress (injetados pelo WP_Hooks.php)
  // Se 'fontalisChat' não existir, o usuário provavelmente não está logado.
  const FONTALIS_DATA = window.fontalisChat || null;
  let currentSessionId = FONTALIS_DATA ? FONTALIS_DATA.session_id : null;
  const originalSessionId = currentSessionId; // ID original da sessão nova (permite edição)
  let previousCartCount = null; // Controle para detectar mudanças reais no carrinho
  let isReadOnlySession = false; // Bloqueia envio em chats do histórico

  // --- Animação de Placeholder com GSAP ---
  const placeholderTexts = [
    "Quero as provas de Astronomia e Cubo mágico do modelo Padrão...",
    "Remova itens do meu carrinho",
    "Preciso das provas da Classe Amigo",
    "Adicione meu cupom de desconto",
  ];

  let placeholderTimeline = null;

  function initPlaceholderAnimation() {
    if (typeof gsap === "undefined") {
      console.warn("GSAP não carregado. Placeholder estático será usado.");
      return;
    }

    const placeholderTextEl = document.getElementById(
      "fontalis-placeholder-text"
    );
    const placeholderCursorEl = document.getElementById(
      "fontalis-placeholder-cursor"
    );

    if (!placeholderTextEl || !placeholderCursorEl) return;

    // Registra o TextPlugin
    if (gsap.registerPlugin) {
      gsap.registerPlugin(TextPlugin);
    }

    let currentIndex = 0;

    placeholderTimeline = gsap.timeline({
      repeat: -1,
      onRepeat: () => {
        currentIndex = (currentIndex + 1) % placeholderTexts.length;
      },
    });

    placeholderTexts.forEach((text, index) => {
      if (index === 0) {
        // Primeira iteração
        placeholderTimeline
          .set(placeholderTextEl, { text: "", opacity: 1 })
          .set(placeholderCursorEl, { opacity: 1, x: 0 })
          .to(
            placeholderTextEl,
            {
              duration: text.length * 0.05,
              text: text,
              ease: "none",
              onUpdate: function () {
                const currentText = placeholderTextEl.textContent;
                const textWidth = placeholderTextEl.offsetWidth;
                gsap.set(placeholderCursorEl, { x: textWidth });
              },
            },
            "+=0.5"
          )
          .to({}, { duration: 1.5 })
          .to(
            placeholderTextEl,
            {
              y: -15,
              opacity: 0,
              filter: "blur(4px)",
              duration: 0.4,
              ease: "back.in(1.7)",
            },
            "+=0"
          )
          .to(
            placeholderCursorEl,
            {
              y: -15,
              opacity: 0,
              filter: "blur(4px)",
              duration: 0.4,
              ease: "back.in(1.7)",
            },
            "<"
          )
          .set([placeholderTextEl, placeholderCursorEl], {
            y: 0,
            filter: "blur(0px)",
          });
      } else {
        // Iterações seguintes
        placeholderTimeline
          .set(placeholderTextEl, { text: "", opacity: 1 })
          .set(placeholderCursorEl, { opacity: 1, x: 0 })
          .to(
            placeholderTextEl,
            {
              duration: text.length * 0.05,
              text: text,
              ease: "none",
              onUpdate: function () {
                const currentText = placeholderTextEl.textContent;
                const textWidth = placeholderTextEl.offsetWidth;
                gsap.set(placeholderCursorEl, { x: textWidth });
              },
            },
            "+=0.3"
          )
          .to({}, { duration: 1.5 })
          .to(
            placeholderTextEl,
            {
              y: -15,
              opacity: 0,
              filter: "blur(4px)",
              duration: 0.4,
              ease: "back.in(1.7)",
            },
            "+=0"
          )
          .to(
            placeholderCursorEl,
            {
              y: -15,
              opacity: 0,
              filter: "blur(4px)",
              duration: 0.4,
              ease: "back.in(1.7)",
            },
            "<"
          )
          .set([placeholderTextEl, placeholderCursorEl], {
            y: 0,
            filter: "blur(0px)",
          });
      }
    });
  }

  // Controle de focus/blur do input
  if (userInput) {
    userInput.addEventListener("focus", () => {
      if (placeholderTimeline) {
        placeholderTimeline.pause();
        gsap.to(
          [
            document.getElementById("fontalis-placeholder-text"),
            document.getElementById("fontalis-placeholder-cursor"),
          ],
          {
            opacity: 0,
            duration: 0.2,
          }
        );
      }
    });

    userInput.addEventListener("blur", () => {
      if (userInput.value.trim() === "" && placeholderTimeline) {
        gsap.to(
          [
            document.getElementById("fontalis-placeholder-text"),
            document.getElementById("fontalis-placeholder-cursor"),
          ],
          {
            opacity: 1,
            duration: 0.2,
            onComplete: () => {
              placeholderTimeline.play();
            },
          }
        );
      }
    });
  }

  // Inicializa a animação quando a página carregar
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPlaceholderAnimation);
  } else {
    initPlaceholderAnimation();
  }

  // --- Funções do Chat ---

  /**
   * Formata o texto da IA, convertendo newlines em <br> e links [texto](url) em <a>.
   * (Esta função foi copiada do message-handler.js)
   */
  /**
   * Formata o texto da IA, convertendo Markdown básico em HTML.
   * Suporta: Negrito, Itálico, Links, Listas e Quebras de linha.
   */
  function formatMessageContent(text) {
    if (typeof text !== "string") {
      return "";
    }

    let formattedText = text;

    // 1. Escapar HTML para segurança (básico)
    formattedText = formattedText
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");

    // 2. Negrito (**texto**)
    formattedText = formattedText.replace(
      /\*\*(.*?)\*\*/g,
      "<strong>$1</strong>"
    );

    // 3. Itálico (*texto*)
    formattedText = formattedText.replace(/\*(.*?)\*/g, "<em>$1</em>");

    // 4. Links [texto](url)
    const linkRegex = /\[([^\]]+)\]\((https?:\/\/[^\s]+)\)/g;
    formattedText = formattedText.replace(
      linkRegex,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>'
    );

    // 5. Listas (* item ou - item)
    // Primeiro, identifica linhas de lista
    const lines = formattedText.split("\n");
    let inList = false;
    let newLines = [];

    lines.forEach((line) => {
      const listMatch = line.match(/^(\s*)([\*\-])\s+(.*)/);

      if (listMatch) {
        if (!inList) {
          newLines.push("<ul>");
          inList = true;
        }
        newLines.push(`<li>${listMatch[3]}</li>`);
      } else {
        if (inList) {
          newLines.push("</ul>");
          inList = false;
        }
        newLines.push(line);
      }
    });

    if (inList) {
      newLines.push("</ul>");
    }

    formattedText = newLines.join("\n");

    // 6. Quebras de linha (apenas fora das listas para não quebrar o HTML)
    // Substitui \n por <br> apenas se não estiver logo após tags de fechamento de bloco
    formattedText = formattedText.replace(/([^>])\n/g, "$1<br>");

    return formattedText;
  }

  // Obter avatar do usuário ou padrão
  function getUserAvatar() {
    if (FONTALIS_DATA && FONTALIS_DATA.user_avatar) {
      return `<img src="${FONTALIS_DATA.user_avatar}" alt="Avatar Usuário" />`;
    }
    return `<i class="fas fa-user"></i>`;
  }

  // Adicionar mensagem ao chat
  function addMessage(message, isUser) {
    const messageDiv = document.createElement("div");
    messageDiv.className = "fontalis-chat-message";

    if (isUser) {
      messageDiv.classList.add("fontalis-message-end");
      messageDiv.innerHTML = `
        <div class="fontalis-message-wrapper fontalis-message-wrapper-user">
            <div class="fontalis-user-message fontalis-message-bubble fontalis-message-bubble-user">
                <p class="fontalis-message-text fontalis-message-text-user">${message}</p>
            </div>
            <div class="fontalis-avatar fontalis-avatar-user ${
              FONTALIS_DATA && FONTALIS_DATA.user_avatar ? "has-avatar" : ""
            }">
                ${getUserAvatar()}
            </div>
        </div>
      `;
    } else {
      messageDiv.classList.add("fontalis-message-start");
      messageDiv.innerHTML = `
        <div class="fontalis-message-wrapper">
            <div class="fontalis-avatar fontalis-avatar-ai">
                <img src="https://www.gravatar.com/avatar/eb67efcf8ad406f8790b64f5b27620c778bf6c5876bfc58141e58be2acdcb131?s=40&d=mp" alt="Avatar IA" />
            </div>
            <div class="fontalis-ai-message fontalis-message-bubble">
                <p class="fontalis-message-text fontalis-message-text-ai">${formatMessageContent(
                  message
                )}</p>
            </div>
        </div>
      `;
    }

    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  // Variável para controlar o intervalo de texto do loader
  let typingInterval;

  // Mostrar indicador de digitação com Avatar Giratório e Status Realista
  function showTypingIndicator() {
    const typingDiv = document.createElement("div");
    typingDiv.id = "fontalis-typingIndicator";
    typingDiv.className = "fontalis-chat-message fontalis-message-start";

    // URL do Avatar da IA (Fontalis)
    const aiAvatar =
      FONTALIS_DATA?.ai_avatar ||
      "https://www.gravatar.com/avatar/eb67efcf8ad406f8790b64f5b27620c778bf6c5876bfc58141e58be2acdcb131?s=40&d=mp";

    typingDiv.innerHTML = `
        <div class="fontalis-message-wrapper">
            <div class="fontalis-loading-container">
                <div class="fontalis-avatar-loading">
                    <img src="${aiAvatar}" alt="Fontalis AI">
                </div>
                <span class="fontalis-loader-text">Pensando sobre isso...</span>
            </div>
        </div>
      `;

    chatMessages.appendChild(typingDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;

    // Sequência de mensagens "Agênticas" para simular processamento real
    // A ordem tenta imitar um fluxo lógico de pensamento de IA
    const messages = [
      "Analisando sua solicitação...",
      "Verificando ferramentas disponíveis...",
      "Consultando base de dados...",
      "Estruturando a melhor resposta...",
      "Finalizando...",
    ];

    let msgIndex = 0;
    const textElement = typingDiv.querySelector(".fontalis-loader-text");

    if (typingInterval) clearInterval(typingInterval);

    // Troca a mensagem a cada 2.5 segundos para dar tempo de leitura
    typingInterval = setInterval(() => {
      msgIndex = (msgIndex + 1) % messages.length;

      // Efeito suave de troca de texto
      textElement.style.opacity = "0";
      setTimeout(() => {
        textElement.textContent = messages[msgIndex];
        textElement.style.opacity = "1";
      }, 200);
    }, 2500);
  }

  // Remover indicador de digitação
  function hideTypingIndicator() {
    const indicator = document.getElementById("fontalis-typingIndicator");
    if (indicator) indicator.remove();
    if (typingInterval) {
      clearInterval(typingInterval);
      typingInterval = null;
    }
  }

  // Ocultar saudação inicial
  function hideGreeting() {
    const greeting = document.querySelector(".fontalis-message-h2");
    if (greeting) {
      // Remove o container pai da mensagem se ele contiver apenas o h2
      const parentMessage = greeting.closest(".fontalis-chat-message");
      if (parentMessage) {
        parentMessage.remove();
      } else {
        greeting.remove();
      }
    }

    // Adiciona classe para expandir altura do container
    const chatMessages = document.getElementById("fontalis-chatMessages");
    if (chatMessages) {
      chatMessages.classList.add("has-messages");
    }

    // Adiciona classe ao container principal para mudar o layout (centralizado -> normal)
    const mainArea = document.querySelector(".fontalis-chat-main-area");
    if (mainArea) {
      mainArea.classList.add("fontalis-chat-active");
    }
  }

  // Enviar mensagem
  async function sendMessage() {
    const message = userInput.value.trim();
    if (!message) return;

    // Verifica se o usuário está logado (se os dados do PHP existem)
    if (!FONTALIS_DATA) {
      addMessage("Você precisa estar logado para usar o chat.", false);
      return;
    }

    // Bloqueia envio em chats do histórico (somente leitura)
    if (isReadOnlySession) {
      addMessage(
        "Esta conversa foi encerrada e está em modo somente leitura. Clique em **Nova Conversa** para iniciar um novo chat.",
        false
      );
      return;
    }

    // Adicionar mensagem do usuário
    addMessage(message, true);
    userInput.value = "";

    // Ocultar saudação inicial se existir
    hideGreeting();

    // Mostrar indicador de digitação
    showTypingIndicator();

    // *** CONEXÃO CORRETA COM O BACKEND WORDPRESS ***
    const formData = new FormData();
    formData.append("action", "fontalis_chat"); // Ação AJAX definida no WP_Hooks.php
    formData.append("message", message);
    // Verifica se chat_id existe, senão usa uma string vazia ou gera um novo se necessário (mas o PHP valida)
    formData.append("session_id", currentSessionId || ""); // Corrigido de chat_id para session_id conforme PHP
    formData.append("nonce", FONTALIS_DATA.nonce); // Segurança: chave fixa 'nonce' conforme esperado pelo check_ajax_referer

    try {
      const response = await fetch(FONTALIS_DATA.ajax_url, {
        method: "POST",
        body: formData,
      });

      const data = await response.json();

      hideTypingIndicator();

      if (data.success) {
        // Sucesso! A IA respondeu.
        // A resposta está em data.data.message
        addMessage(data.data.message, false);

        // Atualiza o carrinho APENAS se o count realmente mudou
        if (
          data.data.cart_count !== undefined &&
          data.data.cart_count !== previousCartCount
        ) {
          previousCartCount = data.data.cart_count;
          updateWooCommerceCart();
        }

        // Atualiza o histórico para refletir a nova mensagem/conversa
        loadChatHistory();
      } else {
        // Ocorreu um erro no PHP (Ex: 403 Nonce inválido, 500 erro da IA)
        // Mostra mensagem de erro mais específica
        const errorMessage =
          data.data?.message || "Ocorreu um erro ao processar sua mensagem.";
        addMessage(errorMessage + " (Código: " + response.status + ")", false);
      }
    } catch (error) {
      // Ocorreu um erro de rede (fetch falhou)
      hideTypingIndicator();
      addMessage(
        "Desculpe, ocorreu um erro de conexão. Tente novamente.",
        false
      );
    }
  }

  // Atualizar carrinho do WooCommerce forçando fetch de fragmentos HTML atualizados
  function updateWooCommerceCart() {
    // Verifica se jQuery e WooCommerce estão disponíveis
    if (typeof jQuery === "undefined" || typeof jQuery.fn.on === "undefined") {
      return;
    }

    // FORÇA O FETCH DOS FRAGMENTOS HTML
    // Este gatilho obriga o WooCommerce a fazer uma chamada AJAX interna ('get_refreshed_fragments')
    // Ele vai buscar no servidor, gerar o HTML do mini-cart e devolver para o navegador.
    jQuery(document.body).trigger("wc_fragment_refresh");

    // Aguarda confirmação da atualização visual
    jQuery(document.body).one("wc_fragments_refreshed", function () {
      // Carrinho atualizado
    });
  }

  // Lidar com teclas
  function handleKeyPress(event) {
    if (event.key === "Enter") {
      event.preventDefault(); // Impede a quebra de linha
      sendMessage();
    }
  }

  function toggleSidebar() {
    if (sidebar) {
      sidebar.classList.toggle("collapsed");
      if (chatContainer) {
        chatContainer.classList.toggle("sidebar-open");
      }
    }
  }

  // Inicializa estado da classe sidebar-open
  if (sidebar && !sidebar.classList.contains("collapsed") && chatContainer) {
    chatContainer.classList.add("sidebar-open");
  }

  if (sidebarToggle) {
    sidebarToggle.addEventListener("click", toggleSidebar);
  }

  if (sidebarToggleInner) {
    sidebarToggleInner.addEventListener("click", toggleSidebar);
  }

  // Search Functionality
  if (searchInput) {
    searchInput.addEventListener("input", function (e) {
      const searchTerm = e.target.value.toLowerCase();
      const historyItems = document.querySelectorAll(".fontalis-history-item");

      historyItems.forEach((item) => {
        const text = item.innerText.toLowerCase();
        if (text.includes(searchTerm)) {
          item.style.display = "flex";
        } else {
          item.style.display = "none";
        }
      });
    });
  }

  if (newChatBtn) {
    newChatBtn.addEventListener("click", function () {
      // Recarrega a página para garantir uma nova sessão limpa
      // Isso garante que o backend gere um novo session_id e os dados de analytics sejam isolados
      window.location.reload();
    });
  }

  // --- History Functions ---
  // Variáveis de controle de paginação do histórico
  let historyOffset = 0;
  const historyLimit = 5;
  let isLoadingHistory = false;
  let hasMoreHistory = true;

  // --- History Functions ---
  async function loadChatHistory(isInitialLoad = false) {
    if (
      !FONTALIS_DATA ||
      isLoadingHistory ||
      (!hasMoreHistory && !isInitialLoad)
    )
      return;

    isLoadingHistory = true;

    if (isInitialLoad) {
      historyOffset = 0;
      hasMoreHistory = true;
      const historyList = document.getElementById("fontalis-history-list");
      if (historyList) historyList.innerHTML = ""; // Limpa lista
    }

    const formData = new FormData();
    formData.append("action", "fontalis_get_history");
    formData.append("nonce", FONTALIS_DATA.nonce);
    formData.append("offset", historyOffset);
    formData.append("limit", historyLimit);

    try {
      const response = await fetch(FONTALIS_DATA.ajax_url, {
        method: "POST",
        body: formData,
      });
      const data = await response.json();

      if (data.success && data.data.history) {
        const historyItems = data.data.history;

        if (historyItems.length < historyLimit) {
          hasMoreHistory = false;
        }

        renderHistory(historyItems);
        historyOffset += historyItems.length;
      } else {
        hasMoreHistory = false;
      }
    } catch (error) {
      // Erro ao carregar histórico
    } finally {
      isLoadingHistory = false;
    }
  }

  function renderHistory(history) {
    const historyList = document.getElementById("fontalis-history-list");
    if (!historyList) return;

    history.forEach((session) => {
      const item = document.createElement("div");
      item.className = `fontalis-history-item ${
        session.session_id === currentSessionId ? "active" : ""
      }`;
      item.title = session.title; // Tooltip nativo para texto truncado
      item.innerHTML = `
        <i class="far fa-comment-alt"></i>
        <span>${session.title}</span>
      `;

      item.addEventListener("click", () => {
        loadSession(session);
      });

      historyList.appendChild(item);
    });
  }

  function loadSession(session) {
    currentSessionId = session.session_id;

    // Marca como somente leitura APENAS se for uma sessão diferente da original
    // Isso permite que o usuário continue editando a sessão atual mesmo se clicar nela no histórico
    isReadOnlySession =
      originalSessionId !== null && session.session_id !== originalSessionId;

    // Atualiza active state na sidebar
    document
      .querySelectorAll(".fontalis-history-item")
      .forEach((el) => el.classList.remove("active"));
    // (Adicionar classe active ao item clicado seria ideal, mas vamos renderizar tudo de novo ou simplificar)

    // Limpa e renderiza mensagens
    chatMessages.innerHTML = "";

    if (session.messages && session.messages.length > 0) {
      session.messages.forEach((msg) => {
        // Só exibe mensagens de usuário e IA (ignora function_call e function_response)
        if (msg.sender_type === "user" || msg.sender_type === "ai") {
          addMessage(msg.message_content, msg.sender_type === "user");
        }
      });
    }

    // Mostra mensagem se não houver mensagens visíveis
    if (chatMessages.innerHTML === "") {
      chatMessages.innerHTML = `
        <div class="fontalis-chat-message fontalis-message-start">
          <div class="fontalis-message-wrapper">
            <div class="fontalis-ai-message fontalis-message-bubble">
              <p class="fontalis-message-text fontalis-message-text-ai">Nenhuma mensagem nesta conversa.</p>
            </div>
          </div>
        </div>
      `;
    }

    // Atualiza estado visual do input
    updateInputState();

    // Fecha sidebar em mobile
    if (window.innerWidth < 768) {
      const sidebar = document.getElementById("fontalis-sidebar");
      if (sidebar) sidebar.classList.add("collapsed");
    }
  }

  // Atualiza estado visual do input baseado em isReadOnlySession
  function updateInputState() {
    const inputWrapper = document.querySelector(".fontalis-input-wrapper");

    if (isReadOnlySession) {
      userInput.disabled = true;
      userInput.placeholder = "Conversa encerrada - somente leitura";
      sendButton.disabled = true;
      if (inputWrapper) inputWrapper.classList.add("fontalis-readonly");

      // Para a animação do placeholder se estiver rodando
      if (placeholderTimeline) {
        placeholderTimeline.pause();
      }

      // Esconde o placeholder animado
      const placeholderContainer = document.getElementById(
        "fontalis-placeholder-container"
      );
      if (placeholderContainer) {
        placeholderContainer.style.display = "none";
      }
    } else {
      userInput.disabled = false;
      userInput.placeholder = "";
      sendButton.disabled = false;
      if (inputWrapper) inputWrapper.classList.remove("fontalis-readonly");

      // Retoma animação do placeholder
      if (placeholderTimeline) {
        placeholderTimeline.play();
      }

      // Mostra o placeholder animado
      const placeholderContainer = document.getElementById(
        "fontalis-placeholder-container"
      );
      if (placeholderContainer) {
        placeholderContainer.style.display = "";
      }
    }
  }

  // Listener de Scroll Infinito para o Histórico
  const historyListEl = document.getElementById("fontalis-history-list");
  if (historyListEl) {
    historyListEl.addEventListener("scroll", () => {
      // Carrega mais quando estiver a 10px do fim
      if (
        historyListEl.scrollTop + historyListEl.clientHeight >=
        historyListEl.scrollHeight - 10
      ) {
        loadChatHistory();
      }
    });
  }

  // Carrega histórico ao iniciar
  loadChatHistory(true);

  userInput.addEventListener("keypress", handleKeyPress);

  // Funções do seu template que não estão conectadas (status, carrinho)
  // Elas podem ser conectadas ao seu backend PHP/WooCommerce se necessário.

  function showStatus(message, type) {
    const statusEl = document.getElementById("fontalis-status");
    if (!statusEl) return;
    statusEl.textContent = message;
    statusEl.style.fontSize = "14px";
    statusEl.style.color = type === "error" ? "#dc2626" : "#10b981";
    setTimeout(() => (statusEl.textContent = ""), 3000);
  }

  function updateCartCount(count) {
    const cartCountEl = document.getElementById("fontalis-cartCount");
    if (!cartCountEl) return;
    if (count > 0) {
      cartCountEl.textContent = `${count} itens`;
      cartCountEl.style.display = "block";
      cartCountEl.classList.add("fontalis-cart-animation");
      setTimeout(
        () => cartCountEl.classList.remove("fontalis-cart-animation"),
        500
      );
    } else {
      cartCountEl.style.display = "none";
    }
  }
}); // Fim do DOMContentLoaded
