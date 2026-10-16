package com.syhnwu.posandroid.screens.components

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.union
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material.icons.filled.ArrowForward
import androidx.compose.material.icons.filled.AutoAwesome
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.OpenInFull
import androidx.compose.material.icons.filled.Send
import androidx.compose.material.icons.rounded.SmartToy
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.FloatingActionButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.TransformOrigin
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.platform.LocalContext
import com.syhnwu.posandroid.network.CopilotApprovalState
import com.syhnwu.posandroid.network.CopilotErrorCodes
import com.syhnwu.posandroid.network.CopilotProposal
import com.syhnwu.posandroid.network.CopilotRejectionState
import com.syhnwu.posandroid.network.FinancialCardDto
import com.syhnwu.posandroid.ui.theme.AppColor
import com.syhnwu.posandroid.utils.SessionManager
import com.syhnwu.posandroid.viewmodel.ProposalUiStatus

// Model Data Dummy (Nanti dipindah ke ViewModel)
data class ChatMessage(
    val text: String,
    val isUser: Boolean,
    val isTyping: Boolean = false,
    val proposal: CopilotProposal? = null,
    val financialCard: FinancialCardDto? = null
)

@Composable
fun FloatingChatbotWidget(
    viewModel: com.syhnwu.posandroid.viewmodel.ChatViewModel = androidx.lifecycle.viewmodel.compose.viewModel(),
    onNavigate: (String) -> Unit = {},
    bottomPadding: androidx.compose.ui.unit.Dp = 16.dp,
    initialExpanded: Boolean = false,
    onClose: (() -> Unit)? = null
) {
    val context = LocalContext.current
    val sessionManager = remember(context) { SessionManager(context) }

    var isExpanded by remember { mutableStateOf(initialExpanded) }
    var isMenuExpanded by remember { mutableStateOf(false) }
    var inputText by remember { mutableStateOf("") }

    val chatHistory by viewModel.chatHistory.collectAsStateWithLifecycle()
    val isTyping by viewModel.isTyping.collectAsStateWithLifecycle()
    val upgradeTrigger by viewModel.pendingUpgradeTrigger.collectAsStateWithLifecycle()
    val approvalState by viewModel.approvalState.collectAsStateWithLifecycle()
    val rejectionState by viewModel.rejectionState.collectAsStateWithLifecycle()
    val proposalStatuses by viewModel.proposalStatuses.collectAsStateWithLifecycle()
    val proposalReceipts by viewModel.proposalReceipts.collectAsStateWithLifecycle()

    var activePinProposal by remember { mutableStateOf<CopilotProposal?>(null) }
    var pinErrorMessage by remember { mutableStateOf<String?>(null) }
    var inlineErrorMessage by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(approvalState) {
        when (val state = approvalState) {
            is CopilotApprovalState.Success -> {
                activePinProposal = null
                pinErrorMessage = null
                inlineErrorMessage = null
            }
            is CopilotApprovalState.Error -> {
                when (state.code) {
                    CopilotErrorCodes.INVALID_PIN -> {
                        pinErrorMessage = "PIN salah. Silakan coba lagi."
                    }
                    CopilotErrorCodes.PROPOSAL_EXPIRED -> {
                        activePinProposal = null
                        inlineErrorMessage = "Persetujuan ini sudah kedaluwarsa."
                    }
                    CopilotErrorCodes.PROPOSAL_NOT_FOUND -> {
                        activePinProposal = null
                        inlineErrorMessage = "Proposal tidak ditemukan pada server."
                    }
                    CopilotErrorCodes.FORBIDDEN_ROLE -> {
                        activePinProposal = null
                        inlineErrorMessage = "Peran staf Anda tidak mencukupi untuk menyetujui aksi ini."
                    }
                    CopilotErrorCodes.CROSS_OUTLET_FORBIDDEN -> {
                        activePinProposal = null
                        inlineErrorMessage = "Staf tidak memiliki wewenang pada outlet ini."
                    }
                    CopilotErrorCodes.STAFF_NOT_FOUND -> {
                        activePinProposal = null
                        inlineErrorMessage = "Staf tidak ditemukan pada sistem."
                    }
                    CopilotErrorCodes.PIN_RATE_LIMITED -> {
                        activePinProposal = null
                        inlineErrorMessage = "Terlalu banyak percobaan PIN. Coba lagi nanti."
                    }
                    CopilotErrorCodes.CONCURRENT_APPROVAL -> {
                        activePinProposal = null
                        inlineErrorMessage = "Aksi ini sedang diproses oleh permintaan lain."
                    }
                    CopilotErrorCodes.MUTATION_FAILED -> {
                        activePinProposal = null
                        inlineErrorMessage = "Gagal mengeksekusi aksi di server."
                    }
                    CopilotErrorCodes.NETWORK_ERROR -> {
                        activePinProposal = null
                        inlineErrorMessage = "Tidak dapat terhubung ke server. Periksa koneksi internet."
                    }
                    else -> {
                        activePinProposal = null
                        inlineErrorMessage = state.message
                    }
                }
            }
            else -> {}
        }
    }

    LaunchedEffect(rejectionState) {
        when (val state = rejectionState) {
            is CopilotRejectionState.Success -> {
                inlineErrorMessage = null
            }
            is CopilotRejectionState.Error -> {
                when (state.code) {
                    CopilotErrorCodes.PROPOSAL_EXPIRED -> inlineErrorMessage = "Persetujuan ini sudah kedaluwarsa."
                    CopilotErrorCodes.PROPOSAL_NOT_FOUND -> inlineErrorMessage = "Proposal tidak ditemukan pada server."
                    CopilotErrorCodes.CONCURRENT_APPROVAL -> inlineErrorMessage = "Aksi ini sedang diproses oleh permintaan lain."
                    CopilotErrorCodes.NETWORK_ERROR -> inlineErrorMessage = "Tidak dapat terhubung ke server. Periksa koneksi internet."
                    else -> inlineErrorMessage = state.message
                }
            }
            else -> {}
        }
    }

    if (isExpanded && onClose != null) {
        BackHandler {
            onClose()
        }
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .windowInsetsPadding(WindowInsets.ime.union(WindowInsets.navigationBars).only(WindowInsetsSides.Bottom)),
        contentAlignment = Alignment.BottomEnd
    ) {

        // --- 1. JENDELA CHAT KACHING AI (SAAT TERBUKA) ---
        AnimatedVisibility(
            visible = isExpanded,
            enter = scaleIn(transformOrigin = TransformOrigin(1f, 1f)) + fadeIn(),
            exit = scaleOut(transformOrigin = TransformOrigin(1f, 1f)) + fadeOut(),
            modifier = Modifier.padding(16.dp)
        ) {
            Surface(
                modifier = Modifier
                    .widthIn(max = 340.dp)
                    .fillMaxWidth(0.9f)
                    .heightIn(max = 480.dp)
                    .fillMaxHeight(0.85f)
                    .shadow(16.dp, RoundedCornerShape(16.dp)),
                shape = RoundedCornerShape(16.dp),
                color = Color.White
            ) {
                Column(modifier = Modifier.fillMaxSize()) {
                    // HEADER
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .background(AppColor.PrimaryBlue)
                            .padding(horizontal = 16.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.weight(1f, fill = false)
                        ) {
                            Icon(Icons.Rounded.SmartToy, contentDescription = null, tint = Color.White)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text(
                                text = "Kaching AI",
                                color = Color.White,
                                fontWeight = FontWeight.Bold,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis
                            )
                        }
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(4.dp)
                        ) {
                            Icon(
                                imageVector = Icons.Default.OpenInFull,
                                contentDescription = "Layar Penuh",
                                tint = Color.White,
                                modifier = Modifier
                                    .size(28.dp)
                                    .clip(CircleShape)
                                    .clickable {
                                        if (onClose != null) {
                                            onClose()
                                        } else {
                                            isExpanded = false
                                        }
                                        onNavigate("copilot_chat")
                                    }
                                    .padding(4.dp)
                            )
                            Icon(
                                imageVector = Icons.Default.Close,
                                contentDescription = "Tutup",
                                tint = Color.White,
                                modifier = Modifier
                                    .size(28.dp)
                                    .clip(CircleShape)
                                    .clickable {
                                        if (onClose != null) {
                                            onClose()
                                        } else {
                                            isExpanded = false
                                        }
                                    }
                                    .padding(4.dp)
                            )
                        }
                    }

                    val listState = rememberLazyListState()

                    // 3. Update Scroll Logic agar peka terhadap Upgrade Card
                    LaunchedEffect(chatHistory.size, isTyping, upgradeTrigger) {
                        val target = if (upgradeTrigger != null) chatHistory.size else if (isTyping) chatHistory.size else chatHistory.size - 1
                        if (target >= 0) listState.animateScrollToItem(target)
                    }

                    // 🛡️ [B.10-B3] Banner Error Inline jika ada kegagalan otorisasi / aksi
                    if (!inlineErrorMessage.isNullOrBlank()) {
                        Surface(
                            color = Color(0xFFFEF2F2),
                            shape = RoundedCornerShape(8.dp),
                            border = BorderStroke(1.dp, Color(0xFFFCA5A5)),
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(horizontal = 12.dp, vertical = 4.dp)
                        ) {
                            Row(
                                modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.SpaceBetween
                            ) {
                                Text(
                                    text = inlineErrorMessage ?: "",
                                    color = Color(0xFFB91C1C),
                                    fontSize = 11.sp,
                                    modifier = Modifier.weight(1f)
                                )
                                Icon(
                                    imageVector = Icons.Default.Close,
                                    contentDescription = "Tutup pesan",
                                    tint = Color(0xFFB91C1C),
                                    modifier = Modifier
                                        .size(16.dp)
                                        .clickable { inlineErrorMessage = null }
                                )
                            }
                        }
                    }

                    LazyColumn(
                        state = listState,
                        modifier = Modifier.weight(1f).padding(horizontal = 12.dp, vertical = 8.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        items(chatHistory) { message ->
                            val proposal = message.proposal
                            val proposalId = proposal?.proposalId
                            val status = proposalId?.let { proposalStatuses[it] } ?: ProposalUiStatus.PENDING
                            val receipt = proposalId?.let { proposalReceipts[it] }
                            val isActionLoading = (approvalState is CopilotApprovalState.Loading && (approvalState as CopilotApprovalState.Loading).proposalId == proposalId) ||
                                (rejectionState is CopilotRejectionState.Loading && (rejectionState as CopilotRejectionState.Loading).proposalId == proposalId)

                            ChatBubble(
                                message = message,
                                onNavigate = onNavigate,
                                onApproveProposal = { prop ->
                                    val staffId = sessionManager.getActiveStaffId()
                                    if (staffId <= 0) {
                                        inlineErrorMessage = "Sesi staf tidak valid. Silakan pilih profil staf terlebih dahulu."
                                    } else {
                                        pinErrorMessage = null
                                        activePinProposal = prop
                                    }
                                },
                                onRejectProposal = { prop ->
                                    viewModel.rejectProposal(prop.proposalId)
                                },
                                proposalUiStatus = status,
                                isActionLoading = isActionLoading,
                                receiptSummary = receipt
                            )
                        }

                        // Sekarang 'upgradeTrigger' sudah dikenal di sini!
                        if (upgradeTrigger != null) {
                            item {
                                UpgradeUpsellCard(
                                    tier = upgradeTrigger!!,
                                    onUpgradeClick = {
                                        viewModel.clearUpgradeTrigger()
                                        onNavigate("upgrade_screen")
                                    }
                                )
                            }
                        }

                        // 👇 [FIX 3] Indikator saat AI sedang mikir
                        if (isTyping) {
                            item {
                                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Start) {
                                    Text("AI sedang mengetik...", fontSize = 12.sp, color = Color.Gray, modifier = Modifier.padding(start = 12.dp))
                                }
                            }
                        }
                    }

                    // AREA INPUT TEKS
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .background(Color(0xFFF9FAFB))
                            .padding(8.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        OutlinedTextField(
                            value = inputText,
                            onValueChange = { inputText = it },
                            placeholder = { Text("Tanya cara sesuatu...", fontSize = 13.sp) },
                            modifier = Modifier.weight(1f),
                            shape = RoundedCornerShape(24.dp),
                            singleLine = true,
                            enabled = !isTyping, // 👈 [FIX 4] Kunci input saat AI mikir
                            colors = OutlinedTextFieldDefaults.colors(
                                focusedBorderColor = AppColor.PrimaryBlue,
                                unfocusedBorderColor = Color.LightGray,
                                focusedContainerColor = Color.White,
                                unfocusedContainerColor = Color.White
                            )
                        )
                        Spacer(modifier = Modifier.width(8.dp))
                        IconButton(
                            onClick = {
                                if (inputText.isNotBlank() && !isTyping) {
                                    // 👇 [FIX 5] Kirim ke ViewModel!
                                    viewModel.sendMessage(inputText)
                                    inputText = ""
                                }
                            },
                            enabled = inputText.isNotBlank() && !isTyping, // 👈 [FIX 6] Cegah kirim kosong
                            modifier = Modifier
                                .size(48.dp)
                                .background(if (inputText.isNotBlank() && !isTyping) AppColor.PrimaryBlue else Color.LightGray, CircleShape)
                        ) {
                            Icon(Icons.Default.Send, contentDescription = "Kirim", tint = Color.White, modifier = Modifier.size(20.dp))
                        }
                    }
                }
            }
        }

        // --- 2. MENU PILIHAN MELAYANG & FAB UTAMA (SAAT JENDELA AI TERTUTUP) ---
        AnimatedVisibility(
            visible = !isExpanded,
            enter = scaleIn() + fadeIn(),
            exit = scaleOut() + fadeOut(),
            modifier = Modifier.padding(end = 16.dp, bottom = bottomPadding)
        ) {
            Column(
                horizontalAlignment = Alignment.End,
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                // Menu Opsi 1: Chat Dapur (Hanya tampil jika menu dibuka)
                AnimatedVisibility(
                    visible = isMenuExpanded,
                    enter = fadeIn() + scaleIn(transformOrigin = TransformOrigin(1f, 1f)),
                    exit = fadeOut() + scaleOut(transformOrigin = TransformOrigin(1f, 1f))
                ) {
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.End
                    ) {
                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = Color(0xFF1E293B),
                            modifier = Modifier.shadow(4.dp, RoundedCornerShape(8.dp))
                        ) {
                            Text(
                                text = "Chat Dapur",
                                color = Color.White,
                                fontSize = 12.sp,
                                fontWeight = FontWeight.Bold,
                                modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                            )
                        }
                        Spacer(modifier = Modifier.width(8.dp))
                        FloatingActionButton(
                            onClick = {
                                isMenuExpanded = false
                                onNavigate("kitchen_chat?orderId=0&orderUuid=")
                            },
                            containerColor = Color(0xFF334155),
                            contentColor = Color.White,
                            shape = CircleShape,
                            modifier = Modifier.size(40.dp),
                            elevation = FloatingActionButtonDefaults.elevation(4.dp)
                        ) {
                            Icon(Icons.Default.Chat, contentDescription = "Chat Dapur", modifier = Modifier.size(20.dp))
                        }
                    }
                }

                // Menu Opsi 2: Kaching AI (Hanya tampil jika menu dibuka)
                AnimatedVisibility(
                    visible = isMenuExpanded,
                    enter = fadeIn() + scaleIn(transformOrigin = TransformOrigin(1f, 1f)),
                    exit = fadeOut() + scaleOut(transformOrigin = TransformOrigin(1f, 1f))
                ) {
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.End
                    ) {
                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = AppColor.PrimaryBlue,
                            modifier = Modifier.shadow(4.dp, RoundedCornerShape(8.dp))
                        ) {
                            Text(
                                text = "Kaching AI",
                                color = Color.White,
                                fontSize = 12.sp,
                                fontWeight = FontWeight.Bold,
                                modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                            )
                        }
                        Spacer(modifier = Modifier.width(8.dp))
                        FloatingActionButton(
                            onClick = {
                                isMenuExpanded = false
                                isExpanded = true
                            },
                            containerColor = AppColor.PrimaryBlue,
                            contentColor = Color.White,
                            shape = CircleShape,
                            modifier = Modifier.size(40.dp),
                            elevation = FloatingActionButtonDefaults.elevation(4.dp)
                        ) {
                            Icon(Icons.Rounded.SmartToy, contentDescription = "Kaching AI", modifier = Modifier.size(20.dp))
                        }
                    }
                }

                // FAB UTAMA (Pemicu Buka/Tutup Menu)
                if (onClose == null || !isExpanded) {
                    FloatingActionButton(
                        onClick = { isMenuExpanded = !isMenuExpanded },
                        modifier = Modifier.size(56.dp),
                        containerColor = if (isMenuExpanded) Color(0xFF64748B) else AppColor.PrimaryBlue,
                        contentColor = Color.White,
                        shape = RoundedCornerShape(16.dp),
                        elevation = FloatingActionButtonDefaults.elevation(
                            defaultElevation = 8.dp,
                            pressedElevation = 8.dp
                        )
                    ) {
                        Icon(
                            imageVector = if (isMenuExpanded) Icons.Default.Close else Icons.Rounded.SmartToy,
                            contentDescription = "Bantuan",
                            modifier = Modifier.size(28.dp)
                        )
                    }
                }
            }
        }
    }

    // 🛡️ [B.10-B3] Dialog PIN Otorisasi Copilot Asinkron
    if (activePinProposal != null) {
        val proposal = activePinProposal!!
        val isPinLoading = approvalState is CopilotApprovalState.Loading &&
            (approvalState as CopilotApprovalState.Loading).proposalId == proposal.proposalId

        CopilotActionPinDialog(
            subtitle = "Masukkan PIN Otorisasi untuk menyetujui ${formatCopilotActionTitle(proposal.action)}",
            isLoading = isPinLoading,
            errorMessage = pinErrorMessage,
            onSubmitPin = { pin ->
                val staffId = sessionManager.getActiveStaffId()
                if (staffId <= 0) {
                    activePinProposal = null
                    inlineErrorMessage = "Sesi staf tidak valid. Silakan pilih profil staf terlebih dahulu."
                } else {
                    viewModel.approveProposal(
                        proposalId = proposal.proposalId,
                        staffId = staffId,
                        pin = pin
                    )
                }
            },
            onCancel = {
                activePinProposal = null
                pinErrorMessage = null
            }
        )
    }
}

data class CopilotQuickAction(
    val title: String,
    val prompt: String
)

val DEFAULT_COPILOT_QUICK_ACTIONS = listOf(
    CopilotQuickAction("Financial Readiness", "Bagaimana kesiapan keuangan bisnis saya?"),
    CopilotQuickAction("Cash Flow", "Bagaimana kondisi cash flow bisnis saya?"),
    CopilotQuickAction("Margin & HPP", "Bagaimana margin dan HPP produk saya?"),
    CopilotQuickAction("Inventory & Restock", "Produk apa yang perlu segera direstock?"),
    CopilotQuickAction("Prediksi Bisnis", "Apa risiko bisnis yang perlu saya antisipasi?")
)

@Composable
fun CopilotChatScreen(
    onBack: () -> Unit,
    onNavigate: (String) -> Unit = {},
    viewModel: com.syhnwu.posandroid.viewmodel.ChatViewModel = androidx.lifecycle.viewmodel.compose.viewModel()
) {
    BackHandler {
        onBack()
    }

    val context = LocalContext.current
    val sessionManager = remember(context) { SessionManager(context) }

    val chatHistory by viewModel.chatHistory.collectAsStateWithLifecycle()
    val isTyping by viewModel.isTyping.collectAsStateWithLifecycle()
    val upgradeTrigger by viewModel.pendingUpgradeTrigger.collectAsStateWithLifecycle()
    val approvalState by viewModel.approvalState.collectAsStateWithLifecycle()
    val rejectionState by viewModel.rejectionState.collectAsStateWithLifecycle()
    val proposalStatuses by viewModel.proposalStatuses.collectAsStateWithLifecycle()
    val proposalReceipts by viewModel.proposalReceipts.collectAsStateWithLifecycle()

    var activePinProposal by remember { mutableStateOf<CopilotProposal?>(null) }
    var pinErrorMessage by remember { mutableStateOf<String?>(null) }
    var inlineErrorMessage by remember { mutableStateOf<String?>(null) }
    var inputText by remember { mutableStateOf("") }

    val listState = rememberLazyListState()

    val hasUserStartedChat = remember(chatHistory) {
        chatHistory.any { it.isUser }
    }

    LaunchedEffect(approvalState) {
        when (val state = approvalState) {
            is CopilotApprovalState.Success -> {
                activePinProposal = null
                pinErrorMessage = null
                inlineErrorMessage = null
            }
            is CopilotApprovalState.Error -> {
                when (state.code) {
                    CopilotErrorCodes.INVALID_PIN -> {
                        pinErrorMessage = "PIN salah. Silakan coba lagi."
                    }
                    CopilotErrorCodes.PROPOSAL_EXPIRED -> {
                        activePinProposal = null
                        inlineErrorMessage = "Persetujuan ini sudah kedaluwarsa."
                    }
                    CopilotErrorCodes.PROPOSAL_NOT_FOUND -> {
                        activePinProposal = null
                        inlineErrorMessage = "Proposal tidak ditemukan pada server."
                    }
                    CopilotErrorCodes.FORBIDDEN_ROLE -> {
                        activePinProposal = null
                        inlineErrorMessage = "Peran staf Anda tidak mencukupi untuk menyetujui aksi ini."
                    }
                    CopilotErrorCodes.CROSS_OUTLET_FORBIDDEN -> {
                        activePinProposal = null
                        inlineErrorMessage = "Staf tidak memiliki wewenang pada outlet ini."
                    }
                    CopilotErrorCodes.STAFF_NOT_FOUND -> {
                        activePinProposal = null
                        inlineErrorMessage = "Staf tidak ditemukan pada sistem."
                    }
                    CopilotErrorCodes.PIN_RATE_LIMITED -> {
                        activePinProposal = null
                        inlineErrorMessage = "Terlalu banyak percobaan PIN. Coba lagi nanti."
                    }
                    CopilotErrorCodes.CONCURRENT_APPROVAL -> {
                        activePinProposal = null
                        inlineErrorMessage = "Aksi ini sedang diproses oleh permintaan lain."
                    }
                    CopilotErrorCodes.MUTATION_FAILED -> {
                        activePinProposal = null
                        inlineErrorMessage = "Gagal mengeksekusi aksi di server."
                    }
                    CopilotErrorCodes.NETWORK_ERROR -> {
                        activePinProposal = null
                        inlineErrorMessage = "Tidak dapat terhubung ke server. Periksa koneksi internet."
                    }
                    else -> {
                        activePinProposal = null
                        inlineErrorMessage = state.message
                    }
                }
            }
            else -> {}
        }
    }

    LaunchedEffect(rejectionState) {
        when (val state = rejectionState) {
            is CopilotRejectionState.Success -> {
                inlineErrorMessage = null
            }
            is CopilotRejectionState.Error -> {
                when (state.code) {
                    CopilotErrorCodes.PROPOSAL_EXPIRED -> inlineErrorMessage = "Persetujuan ini sudah kedaluwarsa."
                    CopilotErrorCodes.PROPOSAL_NOT_FOUND -> inlineErrorMessage = "Proposal tidak ditemukan pada server."
                    CopilotErrorCodes.CONCURRENT_APPROVAL -> inlineErrorMessage = "Aksi ini sedang diproses oleh permintaan lain."
                    CopilotErrorCodes.NETWORK_ERROR -> inlineErrorMessage = "Tidak dapat terhubung ke server. Periksa koneksi internet."
                    else -> inlineErrorMessage = state.message
                }
            }
            else -> {}
        }
    }

    LaunchedEffect(chatHistory.size, isTyping, upgradeTrigger) {
        val totalItems = chatHistory.size + (if (isTyping) 1 else 0) + (if (upgradeTrigger != null) 1 else 0)
        if (totalItems > 0) {
            listState.animateScrollToItem(totalItems - 1)
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(Color(0xFFF8FAFC))
    ) {
        // TOP APP BAR / HEADER
        Surface(
            color = AppColor.PrimaryBlue,
            modifier = Modifier.fillMaxWidth(),
            shadowElevation = 2.dp
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .statusBarsPadding()
                    .padding(horizontal = 8.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                IconButton(onClick = onBack) {
                    Icon(
                        imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                        contentDescription = "Kembali",
                        tint = Color.White
                    )
                }
                Spacer(modifier = Modifier.width(4.dp))
                Column {
                    Text(
                        text = "Kaching AI",
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                        fontSize = 17.sp
                    )
                    Text(
                        text = "Financial & Business Copilot",
                        color = Color.White.copy(alpha = 0.85f),
                        fontSize = 12.sp
                    )
                }
            }
        }

        // INLINE ERROR BANNER JIKA ADA
        if (!inlineErrorMessage.isNullOrBlank()) {
            Surface(
                color = Color(0xFFFEF2F2),
                modifier = Modifier.fillMaxWidth()
            ) {
                Row(
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text(
                        text = inlineErrorMessage ?: "",
                        color = Color(0xFFDC2626),
                        fontSize = 12.sp,
                        modifier = Modifier.weight(1f)
                    )
                    IconButton(
                        onClick = { inlineErrorMessage = null },
                        modifier = Modifier.size(24.dp)
                    ) {
                        Icon(
                            imageVector = Icons.Default.Close,
                            contentDescription = "Tutup",
                            tint = Color(0xFFDC2626),
                            modifier = Modifier.size(16.dp)
                        )
                    }
                }
            }
        }

        // CONTENT AREA (LazyColumn)
        LazyColumn(
            state = listState,
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
                .padding(horizontal = 16.dp),
            contentPadding = PaddingValues(vertical = 16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            if (!hasUserStartedChat) {
                // INITIAL EMPTY STATE: Greeting & Quick Actions
                item {
                    Column(
                        modifier = Modifier.fillMaxWidth(),
                        verticalArrangement = Arrangement.spacedBy(16.dp)
                    ) {
                        // Greeting Card
                        Surface(
                            shape = RoundedCornerShape(16.dp),
                            color = Color.White,
                            border = BorderStroke(1.dp, Color(0xFFE2E8F0)),
                            shadowElevation = 1.dp,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Column(modifier = Modifier.padding(16.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Box(
                                        modifier = Modifier
                                            .size(36.dp)
                                            .background(AppColor.PrimaryBlue.copy(alpha = 0.12f), CircleShape),
                                        contentAlignment = Alignment.Center
                                    ) {
                                        Icon(
                                            imageVector = Icons.Rounded.SmartToy,
                                            contentDescription = null,
                                            tint = AppColor.PrimaryBlue,
                                            modifier = Modifier.size(22.dp)
                                        )
                                    }
                                    Spacer(modifier = Modifier.width(10.dp))
                                    Column {
                                        Text(
                                            text = "Kaching AI",
                                            fontSize = 15.sp,
                                            fontWeight = FontWeight.Bold,
                                            color = Color(0xFF1E293B)
                                        )
                                        Text(
                                            text = "Financial & Business Copilot",
                                            fontSize = 11.sp,
                                            color = Color(0xFF64748B)
                                        )
                                    }
                                }

                                Spacer(modifier = Modifier.height(14.dp))

                                Text(
                                    text = "Halo, saya Kaching AI. Saya bisa membantu memahami kondisi keuangan dan operasional bisnis Anda.",
                                    fontSize = 14.sp,
                                    lineHeight = 20.sp,
                                    color = Color(0xFF334155)
                                )

                                Spacer(modifier = Modifier.height(14.dp))

                                Text(
                                    text = "Mau mulai dari mana?",
                                    fontSize = 13.sp,
                                    fontWeight = FontWeight.SemiBold,
                                    color = Color(0xFF64748B)
                                )
                            }
                        }

                        // Quick Actions List
                        Column(
                            modifier = Modifier.fillMaxWidth(),
                            verticalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            DEFAULT_COPILOT_QUICK_ACTIONS.forEach { action ->
                                Surface(
                                    shape = RoundedCornerShape(12.dp),
                                    color = Color.White,
                                    border = BorderStroke(1.dp, Color(0xFFE2E8F0)),
                                    shadowElevation = 1.dp,
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clickable(enabled = !isTyping) {
                                            viewModel.sendMessage(action.prompt)
                                        }
                                ) {
                                    Row(
                                        modifier = Modifier
                                            .fillMaxWidth()
                                            .padding(horizontal = 14.dp, vertical = 12.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.SpaceBetween
                                    ) {
                                        Row(
                                            verticalAlignment = Alignment.CenterVertically,
                                            modifier = Modifier.weight(1f)
                                        ) {
                                            Icon(
                                                imageVector = Icons.Filled.AutoAwesome,
                                                contentDescription = null,
                                                tint = AppColor.PrimaryBlue,
                                                modifier = Modifier.size(16.dp)
                                            )
                                            Spacer(modifier = Modifier.width(10.dp))
                                            Text(
                                                text = action.title,
                                                fontSize = 13.sp,
                                                fontWeight = FontWeight.SemiBold,
                                                color = Color(0xFF1E293B)
                                            )
                                        }
                                        Icon(
                                            imageVector = Icons.Default.ChevronRight,
                                            contentDescription = null,
                                            tint = Color(0xFF94A3B8),
                                            modifier = Modifier.size(18.dp)
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                // ACTIVE CONVERSATION STATE
                items(chatHistory) { message ->
                    val isProposalLoading = approvalState is CopilotApprovalState.Loading &&
                        (approvalState as CopilotApprovalState.Loading).proposalId == message.proposal?.proposalId

                    val displayMessage = if (!message.isUser && message.text.contains("Halo! Saya Kaching AI")) {
                        message.copy(text = "Halo, saya Kaching AI. Saya bisa membantu memahami kondisi keuangan dan operasional bisnis Anda.")
                    } else {
                        message
                    }

                    val proposal = message.proposal
                    val proposalId = proposal?.proposalId
                    val status = proposalId?.let { proposalStatuses[it] } ?: ProposalUiStatus.PENDING
                    val receipt = proposalId?.let { proposalReceipts[it] }
                    val isActionLoading = (approvalState is CopilotApprovalState.Loading && (approvalState as CopilotApprovalState.Loading).proposalId == proposalId) ||
                        (rejectionState is CopilotRejectionState.Loading && (rejectionState as CopilotRejectionState.Loading).proposalId == proposalId)

                    ChatBubble(
                        message = displayMessage,
                        onNavigate = onNavigate,
                        onApproveProposal = { prop ->
                            val staffId = sessionManager.getActiveStaffId()
                            if (staffId <= 0) {
                                inlineErrorMessage = "Sesi staf tidak valid. Silakan pilih profil staf terlebih dahulu."
                            } else {
                                pinErrorMessage = null
                                activePinProposal = prop
                            }
                        },
                        onRejectProposal = { prop ->
                            viewModel.rejectProposal(prop.proposalId)
                        },
                        proposalUiStatus = status,
                        isActionLoading = isActionLoading,
                        receiptSummary = receipt
                    )
                }

                if (upgradeTrigger != null) {
                    item {
                        UpgradeUpsellCard(
                            tier = upgradeTrigger!!,
                            onUpgradeClick = {
                                viewModel.clearUpgradeTrigger()
                                onNavigate("upgrade_screen")
                            }
                        )
                    }
                }

                if (isTyping) {
                    item {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.padding(start = 4.dp, top = 4.dp)
                        ) {
                            Box(
                                modifier = Modifier
                                    .size(8.dp)
                                    .background(AppColor.PrimaryBlue, CircleShape)
                            )
                            Spacer(modifier = Modifier.width(8.dp))
                            Text(
                                "Kaching AI sedang berpikir...",
                                fontSize = 12.sp,
                                color = Color.Gray
                            )
                        }
                    }
                }
            }
        }

        // BOTTOM INPUT COMPOSER
        Surface(
            color = Color.White,
            modifier = Modifier.fillMaxWidth(),
            shadowElevation = 8.dp
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .windowInsetsPadding(WindowInsets.ime.union(WindowInsets.navigationBars).only(WindowInsetsSides.Bottom))
                    .padding(horizontal = 16.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                OutlinedTextField(
                    value = inputText,
                    onValueChange = { inputText = it },
                    placeholder = { Text("Tanya sesuatu...", fontSize = 14.sp, color = Color.Gray) },
                    modifier = Modifier.weight(1f),
                    shape = RoundedCornerShape(24.dp),
                    maxLines = 4,
                    enabled = !isTyping,
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedBorderColor = AppColor.PrimaryBlue,
                        unfocusedBorderColor = Color(0xFFCBD5E1),
                        focusedContainerColor = Color(0xFFF8FAFC),
                        unfocusedContainerColor = Color(0xFFF8FAFC)
                    )
                )
                Spacer(modifier = Modifier.width(10.dp))
                IconButton(
                    onClick = {
                        if (inputText.isNotBlank() && !isTyping) {
                            val textToSend = inputText.trim()
                            inputText = ""
                            viewModel.sendMessage(textToSend)
                        }
                    },
                    enabled = inputText.isNotBlank() && !isTyping,
                    modifier = Modifier
                        .size(44.dp)
                        .background(
                            if (inputText.isNotBlank() && !isTyping) AppColor.PrimaryBlue else Color(0xFFE2E8F0),
                            CircleShape
                        )
                ) {
                    Icon(
                        imageVector = Icons.Default.Send,
                        contentDescription = "Kirim",
                        tint = if (inputText.isNotBlank() && !isTyping) Color.White else Color(0xFF94A3B8),
                        modifier = Modifier.size(18.dp)
                    )
                }
            }
        }
    }

    // DIALOG PIN OTORISASI
    if (activePinProposal != null) {
        val proposal = activePinProposal!!
        val isPinLoading = approvalState is CopilotApprovalState.Loading &&
            (approvalState as CopilotApprovalState.Loading).proposalId == proposal.proposalId

        CopilotActionPinDialog(
            subtitle = "Masukkan PIN Otorisasi untuk menyetujui ${formatCopilotActionTitle(proposal.action)}",
            isLoading = isPinLoading,
            errorMessage = pinErrorMessage,
            onSubmitPin = { pin ->
                val staffId = sessionManager.getActiveStaffId()
                if (staffId <= 0) {
                    activePinProposal = null
                    inlineErrorMessage = "Sesi staf tidak valid. Silakan pilih profil staf terlebih dahulu."
                } else {
                    viewModel.approveProposal(
                        proposalId = proposal.proposalId,
                        staffId = staffId,
                        pin = pin
                    )
                }
            },
            onCancel = {
                activePinProposal = null
                pinErrorMessage = null
            }
        )
    }
}
