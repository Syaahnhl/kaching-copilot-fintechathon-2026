package com.syhnwu.posandroid.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.syhnwu.posandroid.network.ChatRequest
import com.syhnwu.posandroid.network.CopilotActionApprovalRequest
import com.syhnwu.posandroid.network.CopilotActionApprovalResponse
import com.syhnwu.posandroid.network.CopilotActionRejectionRequest
import com.syhnwu.posandroid.network.CopilotActionRejectionResponse
import com.syhnwu.posandroid.network.CopilotApprovalState
import com.syhnwu.posandroid.network.CopilotErrorCodes
import com.syhnwu.posandroid.network.CopilotProposal
import com.syhnwu.posandroid.network.CopilotRejectionState
import com.syhnwu.posandroid.network.KachingApiService
import com.syhnwu.posandroid.network.RetrofitClient
import com.syhnwu.posandroid.screens.components.ChatMessage
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

class ChatViewModel @JvmOverloads constructor(
    private val api: KachingApiService = RetrofitClient.instance
) : ViewModel() {

    // Riwayat chat, default-nya sapaan awal
    private val _chatHistory = MutableStateFlow<List<ChatMessage>>(
        listOf(
            ChatMessage(
                "Halo! Saya Kaching AI 🤖. Ada yang bisa saya bantu terkait aplikasi ini?",
                isUser = false
            )
        )
    )
    val chatHistory: StateFlow<List<ChatMessage>> = _chatHistory

    // Status apakah AI sedang mengetik/berpikir
    private val _isTyping = MutableStateFlow(false)
    val isTyping: StateFlow<Boolean> = _isTyping

    // 👇 [BARU] State untuk memicu Card Upgrade di UI
    private val _pendingUpgradeTrigger = MutableStateFlow<String?>(null)
    val pendingUpgradeTrigger: StateFlow<String?> = _pendingUpgradeTrigger

    // 🛡️ [B.10-B2] State Persetujuan & Penolakan Aksi Copilot
    private val _approvalState = MutableStateFlow<CopilotApprovalState>(CopilotApprovalState.Idle)
    val approvalState: StateFlow<CopilotApprovalState> = _approvalState.asStateFlow()

    private val _rejectionState = MutableStateFlow<CopilotRejectionState>(CopilotRejectionState.Idle)
    val rejectionState: StateFlow<CopilotRejectionState> = _rejectionState.asStateFlow()

    // 🛡️ [B.10-B3] Status UI per proposalId (Message-specific state, bukan global single-slot)
    private val _proposalStatuses = MutableStateFlow<Map<String, ProposalUiStatus>>(emptyMap())
    val proposalStatuses: StateFlow<Map<String, ProposalUiStatus>> = _proposalStatuses.asStateFlow()

    private val _proposalReceipts = MutableStateFlow<Map<String, String>>(emptyMap())
    val proposalReceipts: StateFlow<Map<String, String>> = _proposalReceipts.asStateFlow()

    val latestProposal: CopilotProposal?
        get() = _chatHistory.value.lastOrNull { !it.isUser }?.proposal

    fun getProposalStatus(proposalId: String): ProposalUiStatus {
        return _proposalStatuses.value[proposalId] ?: ProposalUiStatus.PENDING
    }

    fun setProposalStatus(proposalId: String, status: ProposalUiStatus) {
        _proposalStatuses.update { it + (proposalId to status) }
    }

    fun clearUpgradeTrigger() {
        _pendingUpgradeTrigger.value = null
    }

    fun resetApprovalState() {
        _approvalState.value = CopilotApprovalState.Idle
    }

    fun resetRejectionState() {
        _rejectionState.value = CopilotRejectionState.Idle
    }

    fun sendMessage(text: String) {
        if (text.isBlank()) return

        // 1. Tambah pesan user dengan cara yang lebih aman
        _chatHistory.update { it + ChatMessage(text, isUser = true) }

        viewModelScope.launch {
            _isTyping.value = true
            try {
                val request = ChatRequest(message = text)
                val response = api.sendChatMessage(request)

                if (response.isSuccessful && response.body() != null) {
                    val responseBody = response.body()
                    var replyText = responseBody?.data?.reply ?: responseBody?.reply ?: "Layanan asisten sedang tidak dapat merespons saat ini. Silakan coba beberapa saat lagi."

                    // --- [RADAR UPSELL] ---
                    if (replyText.contains("[TRIGGER_UPGRADE_PRO]")) {
                        replyText = replyText.replace("[TRIGGER_UPGRADE_PRO]", "").trim()
                        _pendingUpgradeTrigger.value = "PRO"
                    } else if (replyText.contains("[TRIGGER_UPGRADE_ULTIMATE]")) {
                        replyText = replyText.replace("[TRIGGER_UPGRADE_ULTIMATE]", "").trim()
                        _pendingUpgradeTrigger.value = "ULTIMATE"
                    }

                    // Canonical source: data.proposal (tidak membaca root proposal)
                    val proposal = responseBody?.data?.proposal
                    val financialCard = responseBody?.data?.financialCard ?: responseBody?.financialCard

                    // 2. Tambah pesan AI beserta structured proposal terverifikasi & financial card
                    _chatHistory.update { it + ChatMessage(replyText, isUser = false, proposal = proposal, financialCard = financialCard) }
                } else {
                    val code = response.code()
                    val errorMessage = when (code) {
                        429 -> "Terlalu banyak permintaan dalam waktu singkat. Mohon tunggu beberapa detik sebelum mencoba lagi."
                        401 -> "Sesi login Anda telah berakhir. Silakan login kembali."
                        403 -> "Akses fitur asisten tidak diizinkan untuk outlet atau profil ini."
                        else -> {
                            val errorBodyString = try { response.errorBody()?.string() } catch (e: Exception) { null }
                            val parsedMessage = try {
                                if (!errorBodyString.isNullOrBlank()) {
                                    val json = JsonParser.parseString(errorBodyString).asJsonObject
                                    json.get("reply")?.asString ?: json.get("message")?.asString
                                } else null
                            } catch (e: Exception) {
                                null
                            }
                            parsedMessage ?: "Maaf, terjadi kendala saat menghubungi server."
                        }
                    }
                    _chatHistory.update { it + ChatMessage(errorMessage, isUser = false) }
                }
            } catch (e: Exception) {
                _chatHistory.update { it + ChatMessage("Koneksi terputus. Cek internetmu ya.", isUser = false) }
            } finally {
                _isTyping.value = false
            }
        }
    }

    /**
     * 🛡️ [B.10-B2] Setujui proposal aksi Copilot.
     * Mengirimkan proposal_id dari server dan PIN otorisasi ke backend B.8.
     * PIN bersifat transien, tidak pernah di-cache/disimpan di ViewModel/StateFlow.
     */
    fun approveProposal(proposalId: String, staffId: Int, pin: String) {
        if (proposalId.isBlank() || pin.isBlank()) {
            _approvalState.value = CopilotApprovalState.Error(
                code = CopilotErrorCodes.VALIDATION_ERROR,
                message = "Proposal ID dan PIN wajib diisi.",
                proposalId = proposalId
            )
            return
        }

        // Concurrency Guard 1: Cegah duplicate in-flight approval request
        if (_approvalState.value is CopilotApprovalState.Loading) {
            return
        }

        // Concurrency Guard 2: Cegah eksekusi approval jika penolakan proposal yang sama sedang in-flight
        val currentRejection = _rejectionState.value
        if (currentRejection is CopilotRejectionState.Loading && currentRejection.proposalId == proposalId) {
            return
        }

        // Set Loading state synchronously untuk memblokir fast duplicate invocation
        _approvalState.value = CopilotApprovalState.Loading(proposalId)
        _proposalStatuses.update { it + (proposalId to ProposalUiStatus.APPROVING) }

        viewModelScope.launch {
            try {
                val request = CopilotActionApprovalRequest(
                    proposalId = proposalId,
                    staffId = staffId,
                    pin = pin
                )
                val response = api.approveCopilotAction(request)
                if (response.isSuccessful && response.body() != null) {
                    val body = response.body()!!
                    _approvalState.value = CopilotApprovalState.Success(body)
                    val executedId = body.data?.proposalId ?: proposalId
                    _proposalStatuses.update { it + (executedId to ProposalUiStatus.EXECUTED) }
                    val receiptSummary = extractReceiptSummary(body.data?.receipt)
                    if (receiptSummary != null) {
                        _proposalReceipts.update { it + (executedId to receiptSummary) }
                    }
                } else {
                    val errorBody = response.errorBody()?.string()
                    val parsed = parseApiError(errorBody, response.code())
                    _approvalState.value = CopilotApprovalState.Error(
                        code = parsed.first,
                        message = parsed.second,
                        proposalId = proposalId
                    )
                    val uiStatus = when (parsed.first) {
                        CopilotErrorCodes.PROPOSAL_EXPIRED -> ProposalUiStatus.EXPIRED
                        CopilotErrorCodes.INVALID_PIN -> ProposalUiStatus.PENDING
                        CopilotErrorCodes.NETWORK_ERROR -> ProposalUiStatus.PENDING
                        else -> ProposalUiStatus.ERROR
                    }
                    _proposalStatuses.update { it + (proposalId to uiStatus) }
                }
            } catch (e: Exception) {
                val isNetwork = e is java.io.IOException
                _approvalState.value = CopilotApprovalState.Error(
                    code = if (isNetwork) CopilotErrorCodes.NETWORK_ERROR else CopilotErrorCodes.UNKNOWN_ERROR,
                    message = e.message ?: "Terjadi kesalahan saat memproses persetujuan.",
                    proposalId = proposalId
                )
                val uiStatus = if (isNetwork) ProposalUiStatus.PENDING else ProposalUiStatus.ERROR
                _proposalStatuses.update { it + (proposalId to uiStatus) }
            }
        }
    }

    /**
     * 🛡️ [B.10-B2] Tolak proposal aksi Copilot dan batalkan dari cache server.
     */
    fun rejectProposal(proposalId: String, reason: String? = null) {
        if (proposalId.isBlank()) {
            _rejectionState.value = CopilotRejectionState.Error(
                code = CopilotErrorCodes.VALIDATION_ERROR,
                message = "Proposal ID wajib diisi.",
                proposalId = proposalId
            )
            return
        }

        // Concurrency Guard 1: Cegah duplicate in-flight rejection request
        if (_rejectionState.value is CopilotRejectionState.Loading) {
            return
        }

        // Concurrency Guard 2: Cegah eksekusi rejection jika approval proposal yang sama sedang in-flight
        val currentApproval = _approvalState.value
        if (currentApproval is CopilotApprovalState.Loading && currentApproval.proposalId == proposalId) {
            return
        }

        // Set Loading state synchronously untuk memblokir fast duplicate invocation
        _rejectionState.value = CopilotRejectionState.Loading(proposalId)
        _proposalStatuses.update { it + (proposalId to ProposalUiStatus.REJECTING) }

        viewModelScope.launch {
            try {
                val request = CopilotActionRejectionRequest(
                    proposalId = proposalId,
                    reason = reason
                )
                val response = api.rejectCopilotAction(request)
                if (response.isSuccessful && response.body() != null) {
                    val body = response.body()!!
                    _rejectionState.value = CopilotRejectionState.Success(body)
                    val rejectedId = body.data?.proposalId ?: proposalId
                    _proposalStatuses.update { it + (rejectedId to ProposalUiStatus.REJECTED) }
                } else {
                    val errorBody = response.errorBody()?.string()
                    val parsed = parseApiError(errorBody, response.code())
                    _rejectionState.value = CopilotRejectionState.Error(
                        code = parsed.first,
                        message = parsed.second,
                        proposalId = proposalId
                    )
                    val uiStatus = when (parsed.first) {
                        CopilotErrorCodes.PROPOSAL_EXPIRED -> ProposalUiStatus.EXPIRED
                        CopilotErrorCodes.NETWORK_ERROR -> ProposalUiStatus.PENDING
                        else -> ProposalUiStatus.ERROR
                    }
                    _proposalStatuses.update { it + (proposalId to uiStatus) }
                }
            } catch (e: Exception) {
                val isNetwork = e is java.io.IOException
                _rejectionState.value = CopilotRejectionState.Error(
                    code = if (isNetwork) CopilotErrorCodes.NETWORK_ERROR else CopilotErrorCodes.UNKNOWN_ERROR,
                    message = e.message ?: "Terjadi kesalahan saat membatalkan proposal.",
                    proposalId = proposalId
                )
                val uiStatus = if (isNetwork) ProposalUiStatus.PENDING else ProposalUiStatus.ERROR
                _proposalStatuses.update { it + (proposalId to uiStatus) }
            }
        }
    }

    internal fun parseApiError(errorBody: String?, httpStatusCode: Int): Pair<String, String> {
        if (!errorBody.isNullOrBlank()) {
            try {
                val json = JsonParser.parseString(errorBody).asJsonObject
                val code = if (json.has("code") && !json.get("code").isJsonNull) json.get("code").asString else null
                val message = if (json.has("message") && !json.get("message").isJsonNull) json.get("message").asString else null
                if (!code.isNullOrBlank()) {
                    return code to (message ?: "Terjadi kesalahan pada server.")
                }
                if (httpStatusCode == 429) {
                    return CopilotErrorCodes.RATE_LIMITED to (message ?: "Terlalu banyak permintaan. Silakan tunggu.")
                }
                if (!message.isNullOrBlank()) {
                    return mapHttpCodeToErrorCode(httpStatusCode) to message
                }
            } catch (_: Exception) {
                // Ignore parsing failure, fall through to HTTP code fallback
            }
        }
        return mapHttpCodeToErrorCode(httpStatusCode) to "HTTP error $httpStatusCode"
    }

    internal fun extractReceiptSummary(receipt: JsonObject?): String? {
        if (receipt == null || receipt.isJsonNull || receipt.entrySet().isEmpty()) return null
        val parts = mutableListOf<String>()
        if (receipt.has("name") && !receipt.get("name").isJsonNull) {
            parts.add(receipt.get("name").asString)
        }
        if (receipt.has("amount") && !receipt.get("amount").isJsonNull) {
            val amt = receipt.get("amount").asDouble
            parts.add("Rp" + String.format(java.util.Locale.US, "%,d", amt.toLong()).replace(',', '.'))
        }
        if (receipt.has("new_price") && !receipt.get("new_price").isJsonNull) {
            val price = receipt.get("new_price").asDouble
            parts.add("Harga: Rp" + String.format(java.util.Locale.US, "%,d", price.toLong()).replace(',', '.'))
        }
        if (receipt.has("quantity") && !receipt.get("quantity").isJsonNull) {
            val qty = receipt.get("quantity").asDouble
            val qtyStr = if (qty % 1.0 == 0.0) qty.toInt().toString() else qty.toString()
            parts.add("Jumlah: $qtyStr")
        }
        return if (parts.isNotEmpty()) parts.joinToString(" • ") else null
    }

    private fun mapHttpCodeToErrorCode(statusCode: Int): String {
        return when (statusCode) {
            401 -> CopilotErrorCodes.UNAUTHORIZED
            403 -> CopilotErrorCodes.FORBIDDEN_ROLE
            404 -> CopilotErrorCodes.PROPOSAL_NOT_FOUND
            409 -> CopilotErrorCodes.CONCURRENT_APPROVAL
            410 -> CopilotErrorCodes.PROPOSAL_EXPIRED
            422 -> CopilotErrorCodes.VALIDATION_ERROR
            429 -> CopilotErrorCodes.RATE_LIMITED
            else -> CopilotErrorCodes.UNKNOWN_ERROR
        }
    }
}

/**
 * 🛡️ [B.10-B3] Status siklus hidup UI per-proposal aksi Copilot.
 * Terikat per proposal_id untuk isolasi antar pesan.
 */
enum class ProposalUiStatus {
    PENDING,
    APPROVING,
    EXECUTED,
    REJECTING,
    REJECTED,
    EXPIRED,
    ERROR
}