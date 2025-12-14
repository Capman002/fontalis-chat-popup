import { FontalisLogger } from "../core/logger.js";

class MessageHandler {
  constructor(aiGravatarUrl, userName) {
    this.aiGravatarUrl = aiGravatarUrl;
    this.userName = userName;
  }

  formatMessageContent(text) {
    // Substitui quebras de linha por <br>
    let formattedText = text.replace(/\n/g, "<br>");

    // Regex para encontrar links no formato [texto](url)
    const linkRegex = /\[([^\]]+)\]\((https?:\/\/[^\s]+)\)/g;

    // Substitui links pelo formato HTML <a>
    formattedText = formattedText.replace(
      linkRegex,
      '<a href="$2" target="_blank">$1</a>'
    );

    return formattedText;
  }

  addMessage(content, sender) {
    const chatMessages = document.getElementById("messagesContainer");
    if (!chatMessages) {
      FontalisLogger.error("MessageHandler: messagesContainer não encontrado.");
      return;
    }

    const messageElement = document.createElement("div");
    messageElement.classList.add("message", `${sender}-message`);

    const gravatarHash =
      "eb67efcf8ad406f8790b64f5b27620c778bf6c5876bfc58141e58be2acdcb131";
    const gravatarUrl = `https://www.gravatar.com/avatar/${gravatarHash}?s=40&d=mp`;

    let avatarHtml = "";
    if (sender === "ai") {
      avatarHtml = `
        <div class="message-avatar ${sender}-avatar">
          <img src="${gravatarUrl}" alt="Fontalis AI" class="gravatar-img" />
        </div>
      `;
    }

    messageElement.innerHTML = `
      ${avatarHtml}
      <div class="message-content">
        <p class="message-text">${this.formatMessageContent(content)}</p>
      </div>
    `;

    chatMessages.appendChild(messageElement);
  }
}

export { MessageHandler };
